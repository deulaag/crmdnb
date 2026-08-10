<?php
declare(strict_types=1);

/** Serviços do WhatsApp: webhook, idempotência, buffer, fila e Human Lock. */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/whatsapp_provider.php';

// A configuração histórica do banco permanece intocada. As constantes de IA
// ausentes são obtidas do ambiente para que o processador do WhatsApp funcione.
if (!defined('AI_VPS_ENDPOINT')) define('AI_VPS_ENDPOINT', getenv('DNB_AI_VPS_ENDPOINT') ?: '');
if (!defined('AI_VPS_TOKEN')) define('AI_VPS_TOKEN', getenv('DNB_AI_VPS_TOKEN') ?: '');
if (!defined('AI_REQUEST_TIMEOUT')) define('AI_REQUEST_TIMEOUT', max(5, (int) (getenv('DNB_AI_TIMEOUT') ?: 45)));
if (!defined('AI_MAX_PROMPT_LENGTH')) define('AI_MAX_PROMPT_LENGTH', 8000);

require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/lead_service.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/followup_service.php';

const WHATSAPP_BUFFER_SECONDS = 5;
const WHATSAPP_MAX_MESSAGE_LENGTH = 4000;

class WhatsappServiceException extends RuntimeException {}

/** @param array<string,mixed> $dados */
function whatsappValor(array $dados, array $caminhos, mixed $padrao = null): mixed
{
    foreach ($caminhos as $caminho) {
        $valor = $dados;
        $encontrado = true;
        foreach (explode('.', (string) $caminho) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) { $encontrado = false; break; }
            $valor = $valor[$parte];
        }
        if ($encontrado && $valor !== null && $valor !== '') return $valor;
    }
    return $padrao;
}

function normalizarTelefoneWhatsapp(string $telefone): string
{
    $telefone = preg_replace('/@.*$/', '', trim($telefone)) ?? '';
    return preg_replace('/\D+/', '', $telefone) ?? '';
}

function whatsappDirecao(mixed $valor): string
{
    if (is_bool($valor)) return $valor ? 'outgoing' : 'incoming';
    $valor = strtolower(trim((string) $valor));
    return in_array($valor, ['outgoing', 'outbound', 'sent', 'true', '1'], true) ? 'outgoing' : 'incoming';
}

/**
 * Normaliza formatos comuns sem acoplar o CRM a um fornecedor específico.
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function normalizarEventoWhatsapp(array $payload): array
{
    $instancia = trim((string) whatsappValor($payload, ['instance_id','instanceId','instance.id','data.instance_id','data.instanceId']));
    $mensagemId = trim((string) whatsappValor($payload, ['message_id','messageId','message.id','data.key.id','data.message_id','data.id','id']));
    $telefoneBruto = (string) whatsappValor($payload, ['phone','from','remote_jid','message.from','data.key.remoteJid','data.from']);
    $telefone = normalizarTelefoneWhatsapp($telefoneBruto);
    $corpo = trim((string) whatsappValor($payload, [
        'message_body','body','text','message.text','message.body','data.message.conversation',
        'data.message.extendedTextMessage.text','data.text','data.body',
    ], ''));
    $media = trim((string) whatsappValor($payload, ['media_url','message.media_url','data.media_url','data.url'], ''));
    if ($media !== '') {
        $partesMedia = parse_url($media);
        if ($partesMedia === false || ($partesMedia['scheme'] ?? '') !== 'https' || empty($partesMedia['host'])) $media = '';
    }
    $fromMe = whatsappValor($payload, ['from_me','fromMe','message.from_me','data.key.fromMe','direction'], false);
    $leadId = (int) whatsappValor($payload, ['lead_id','message.lead_id','data.lead_id'], 0);
    $timestamp = whatsappValor($payload, ['timestamp','message.timestamp','data.messageTimestamp','data.timestamp'], null);
    if (is_numeric($timestamp)) {
        $segundos = (float) $timestamp > 20000000000 ? ((float) $timestamp / 1000) : (float) $timestamp;
        $criadoEm = date('Y-m-d H:i:s', (int) $segundos);
    } else {
        $criadoEm = date('Y-m-d H:i:s');
    }

    if ($instancia === '') throw new InvalidArgumentException('Instância ausente no webhook.');
    if ($mensagemId === '') throw new InvalidArgumentException('ID da mensagem ausente no webhook.');
    if ($telefone === '') throw new InvalidArgumentException('Contato ausente no webhook.');
    if ($corpo === '' && $media === '') throw new InvalidArgumentException('Mensagem sem texto ou mídia.');
    if (mb_strlen($corpo) > WHATSAPP_MAX_MESSAGE_LENGTH) $corpo = mb_substr($corpo, 0, WHATSAPP_MAX_MESSAGE_LENGTH);

    return [
        'instance_external_id' => mb_substr($instancia, 0, 120),
        'instance_name' => mb_substr((string) whatsappValor($payload, ['instance_name','instance.name','data.instance_name'], $instancia), 0, 160),
        'provider' => mb_substr((string) whatsappValor($payload, ['provider','source'], 'generic'), 0, 60),
        'provider_message_id' => mb_substr($mensagemId, 0, 190),
        'external_contact_id' => mb_substr($telefoneBruto !== '' ? $telefoneBruto : $telefone, 0, 160),
        'phone' => mb_substr($telefone, 0, 40),
        'display_name' => mb_substr((string) whatsappValor($payload, ['contact_name','push_name','message.contact_name','data.pushName'], ''), 0, 180),
        'lead_id' => $leadId > 0 ? $leadId : null,
        'direction' => whatsappDirecao($fromMe),
        'message_body' => $corpo,
        'media_url' => $media !== '' ? mb_substr($media, 0, 1000) : null,
        'created_at' => $criadoEm,
    ];
}

function whatsappTokenValido(string $esperado, string $recebido): bool
{
    return $esperado !== '' && $recebido !== '' && hash_equals($esperado, $recebido);
}

function whatsappTokenCabecalho(): string
{
    $direto = trim((string) ($_SERVER['HTTP_X_DNB_WEBHOOK_TOKEN'] ?? $_SERVER['HTTP_X_DNB_CRON_TOKEN'] ?? ''));
    if ($direto !== '') return $direto;
    $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : '';
}

function whatsappBufferDisponivelEm(DateTimeImmutable $ultimaMensagem): DateTimeImmutable
{
    return $ultimaMensagem->modify('+' . WHATSAPP_BUFFER_SECONDS . ' seconds');
}

/** @param array<int,array<string,mixed>> $mensagens */
function agruparMensagensWhatsapp(array $mensagens): string
{
    $partes = [];
    foreach ($mensagens as $mensagem) {
        $texto = trim((string) ($mensagem['message_body'] ?? ''));
        if ($texto !== '') $partes[] = $texto;
        elseif (!empty($mensagem['media_url'])) $partes[] = '[Mídia recebida: ' . $mensagem['media_url'] . ']';
    }
    return mb_substr(implode("\n", $partes), 0, AI_MAX_PROMPT_LENGTH);
}

/** @param array<string,mixed> $conversa */
function whatsappTravaHumanaAtiva(array $conversa): bool
{
    return !empty($conversa['ai_paused']) || !empty($conversa['assigned_to_user_id']);
}

/** @param array<string,mixed> $conversa @param array<string,mixed> $usuario */
function usuarioPodeAlterarTravaWhatsapp(array $conversa, array $usuario): bool
{
    return usuarioContextoTemPapel($usuario, PAPEL_ADMINISTRADOR)
        || empty($conversa['assigned_to_user_id'])
        || (int) $conversa['assigned_to_user_id'] === (int) ($usuario['id'] ?? 0);
}

/** @param array<string,mixed> $evento @return array<string,mixed> */
function registrarWebhookWhatsapp(PDO $pdo, array $evento): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO whatsapp_instances (external_instance_id,name,provider,status,created_at,updated_at)
            VALUES (?,?,?,'active',NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),name=VALUES(name),provider=VALUES(provider),updated_at=NOW()");
        $stmt->execute([$evento['instance_external_id'], $evento['instance_name'], $evento['provider']]);
        $instanceId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT id FROM whatsapp_messages WHERE instance_id=? AND provider_message_id=? LIMIT 1');
        $stmt->execute([$instanceId, $evento['provider_message_id']]);
        $duplicadaId = (int) $stmt->fetchColumn();
        if ($duplicadaId) {
            $pdo->commit();
            return ['duplicate' => true, 'message_id' => $duplicadaId];
        }

        $leadId = $evento['lead_id'] ?? null;
        if ($leadId) {
            $stmt = $pdo->prepare('SELECT id FROM orcamentos WHERE id=?');
            $stmt->execute([(int) $leadId]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Lead informado no webhook não existe.');
        }

        $stmt = $pdo->prepare("INSERT INTO whatsapp_contacts (instance_id,external_contact_id,phone,display_name,lead_id,created_at,updated_at)
            VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),phone=VALUES(phone),
            display_name=COALESCE(NULLIF(VALUES(display_name),''),display_name),lead_id=COALESCE(lead_id,VALUES(lead_id)),updated_at=NOW()");
        $stmt->execute([$instanceId, $evento['external_contact_id'], $evento['phone'], $evento['display_name'], $leadId]);
        $contactId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT lead_id FROM whatsapp_contacts WHERE id=?');
        $stmt->execute([$contactId]);
        $leadId = ($id = (int) $stmt->fetchColumn()) > 0 ? $id : null;

        $stmt = $pdo->prepare("INSERT INTO whatsapp_conversations (instance_id,contact_id,lead_id,status,last_message_at,created_at,updated_at)
            VALUES (?,?,?,'open',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),lead_id=COALESCE(lead_id,VALUES(lead_id)),
            status='open',last_message_at=GREATEST(COALESCE(last_message_at,'1970-01-01'),VALUES(last_message_at)),updated_at=NOW()");
        $stmt->execute([$instanceId, $contactId, $leadId, $evento['created_at']]);
        $conversationId = (int) $pdo->lastInsertId();

        $senderType = $evento['direction'] === 'incoming' ? 'contact' : 'system';
        $status = $evento['direction'] === 'incoming' ? 'queued' : 'sent';
        $stmt = $pdo->prepare('INSERT INTO whatsapp_messages (provider_message_id,instance_id,conversation_id,lead_id,direction,sender_type,message_body,media_url,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$evento['provider_message_id'], $instanceId, $conversationId, $leadId, $evento['direction'], $senderType, $evento['message_body'], $evento['media_url'], $status, $evento['created_at']]);
        $messageId = (int) $pdo->lastInsertId();
        $preview = $evento['message_body'] !== '' ? $evento['message_body'] : '[Mídia]';
        $incremento = $evento['direction'] === 'incoming' ? 1 : 0;
        $pdo->prepare("UPDATE whatsapp_conversations SET lead_id=COALESCE(lead_id,?),
            last_message_preview=IF(last_message_at<=?, ?, last_message_preview),
            last_message_at=GREATEST(COALESCE(last_message_at,'1970-01-01'),?),unread_count=unread_count+?,updated_at=NOW() WHERE id=?")
            ->execute([$leadId, $evento['created_at'], mb_substr($preview, 0, 500), $evento['created_at'], $incremento, $conversationId]);

        if ($evento['direction'] === 'incoming') {
            $stmt = $pdo->prepare("INSERT INTO whatsapp_queue (conversation_id,first_message_id,last_message_id,first_message_at,last_message_at,available_at,status,attempts,created_at,updated_at)
                VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 SECOND),'pending',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE
                first_message_id=IF(status IN ('completed','skipped','failed'),VALUES(first_message_id),LEAST(first_message_id,VALUES(first_message_id))),
                last_message_id=IF(status IN ('completed','skipped','failed'),VALUES(last_message_id),GREATEST(last_message_id,VALUES(last_message_id))),
                first_message_at=IF(status IN ('completed','skipped','failed'),VALUES(first_message_at),LEAST(first_message_at,VALUES(first_message_at))),
                last_message_at=IF(status IN ('completed','skipped','failed'),VALUES(last_message_at),GREATEST(last_message_at,VALUES(last_message_at))),
                available_at=DATE_ADD(NOW(),INTERVAL 5 SECOND),attempts=IF(status IN ('completed','skipped','failed'),0,attempts),
                locked_at=NULL,processed_at=NULL,error_message=NULL,ai_interaction_id=NULL,status='pending',updated_at=NOW()");
            $stmt->execute([$conversationId, $messageId, $messageId, $evento['created_at'], $evento['created_at']]);
            if ($leadId) interromperFollowupPorMensagemRecebida($pdo, (int) $leadId, $messageId, (string) $evento['created_at']);
        }
        $pdo->commit();
        return ['duplicate' => false, 'message_id' => $messageId, 'conversation_id' => $conversationId, 'lead_id' => $leadId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<int,array<string,mixed>> */
function listarConversasWhatsapp(PDO $pdo, int $limite = 100): array
{
    [$filtro, $params] = filtroEscopoWhatsapp($pdo, 'wc');
    $limite = max(1, min(200, $limite));
    $stmt = $pdo->prepare("SELECT wc.*,ct.phone,ct.display_name,wi.name instance_name,o.numero lead_number,o.cliente lead_customer,
        owner.nome lead_owner_name,human.nome human_assignee_name
        FROM whatsapp_conversations wc
        JOIN whatsapp_contacts ct ON ct.id=wc.contact_id JOIN whatsapp_instances wi ON wi.id=wc.instance_id
        LEFT JOIN orcamentos o ON o.id=wc.lead_id LEFT JOIN usuarios owner ON owner.id=o.assigned_to_user_id
        LEFT JOIN usuarios human ON human.id=wc.assigned_to_user_id
        WHERE {$filtro} ORDER BY wc.last_message_at DESC,wc.id DESC LIMIT {$limite}");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function buscarConversaWhatsapp(PDO $pdo, int $conversationId): ?array
{
    if (!podeAcessarConversaWhatsapp($pdo, $conversationId)) return null;
    $stmt = $pdo->prepare("SELECT wc.*,ct.phone,ct.display_name,ct.external_contact_id,wi.name instance_name,wi.external_instance_id,
        wi.provider,o.numero lead_number,o.cliente lead_customer,o.assigned_to_user_id lead_owner_id,
        owner.nome lead_owner_name,human.nome human_assignee_name
        FROM whatsapp_conversations wc
        JOIN whatsapp_contacts ct ON ct.id=wc.contact_id JOIN whatsapp_instances wi ON wi.id=wc.instance_id
        LEFT JOIN orcamentos o ON o.id=wc.lead_id LEFT JOIN usuarios owner ON owner.id=o.assigned_to_user_id
        LEFT JOIN usuarios human ON human.id=wc.assigned_to_user_id WHERE wc.id=? LIMIT 1");
    $stmt->execute([$conversationId]);
    return $stmt->fetch() ?: null;
}

/** @return array<int,array<string,mixed>> */
function listarMensagensWhatsapp(PDO $pdo, int $conversationId, int $depoisId = 0, int $limite = 200): array
{
    if (!podeAcessarConversaWhatsapp($pdo, $conversationId)) throw new WhatsappServiceException('Conversa fora do seu escopo.');
    $limite = max(1, min(500, $limite));
    $ordem = $depoisId > 0 ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare("SELECT wm.*,u.nome sender_user_name FROM whatsapp_messages wm
        LEFT JOIN usuarios u ON u.id=wm.sender_user_id WHERE wm.conversation_id=? AND wm.id>? ORDER BY wm.id {$ordem} LIMIT {$limite}");
    $stmt->execute([$conversationId, $depoisId]);
    $mensagens = $stmt->fetchAll();
    return $depoisId > 0 ? $mensagens : array_reverse($mensagens);
}

/** @return array<string,mixed> */
function alterarTravaHumanaWhatsapp(PDO $pdo, int $conversationId, string $acao, array $usuario): array
{
    $conversa = buscarConversaWhatsapp($pdo, $conversationId);
    if (!$conversa) throw new WhatsappServiceException('Conversa não encontrada ou fora do seu escopo.');
    if (!usuarioPodeAlterarTravaWhatsapp($conversa, $usuario)) {
        throw new WhatsappServiceException('Esta conversa já foi assumida por outro atendente.');
    }
    if ($acao === 'assume' && (int) ($conversa['assigned_to_user_id'] ?? 0) === (int) $usuario['id'] && whatsappTravaHumanaAtiva($conversa)) {
        return $conversa;
    }
    if ($acao === 'release' && !whatsappTravaHumanaAtiva($conversa)) return $conversa;
    if ($acao === 'assume') {
        if (usuarioContextoTemPapel($usuario, PAPEL_ADMINISTRADOR)) {
            $stmt = $pdo->prepare('UPDATE whatsapp_conversations SET assigned_to_user_id=?,ai_paused=1,human_locked_at=NOW(),updated_at=NOW() WHERE id=?');
            $stmt->execute([(int) $usuario['id'], $conversationId]);
        } else {
            $stmt = $pdo->prepare('UPDATE whatsapp_conversations SET assigned_to_user_id=?,ai_paused=1,human_locked_at=NOW(),updated_at=NOW() WHERE id=? AND (assigned_to_user_id IS NULL OR assigned_to_user_id=?)');
            $stmt->execute([(int) $usuario['id'], $conversationId, (int) $usuario['id']]);
        }
        if ($stmt->rowCount() === 0) throw new WhatsappServiceException('Outro atendente assumiu esta conversa. Atualize a página.');
        $descricao = 'Atendimento WhatsApp assumido por ' . $usuario['nome'] . '; IA pausada pelo Human Lock.';
    } elseif ($acao === 'release') {
        if (usuarioContextoTemPapel($usuario, PAPEL_ADMINISTRADOR)) {
            $stmt = $pdo->prepare('UPDATE whatsapp_conversations SET assigned_to_user_id=NULL,ai_paused=0,human_locked_at=NULL,updated_at=NOW() WHERE id=?');
            $stmt->execute([$conversationId]);
        } else {
            $stmt = $pdo->prepare('UPDATE whatsapp_conversations SET assigned_to_user_id=NULL,ai_paused=0,human_locked_at=NULL,updated_at=NOW() WHERE id=? AND assigned_to_user_id=?');
            $stmt->execute([$conversationId, (int) $usuario['id']]);
        }
        if ($stmt->rowCount() === 0) throw new WhatsappServiceException('O Human Lock foi alterado por outro atendente. Atualize a página.');
        $descricao = 'Atendimento WhatsApp liberado por ' . $usuario['nome'] . '; IA reativada.';
    } else {
        throw new InvalidArgumentException('Ação de atendimento inválida.');
    }
    if (!empty($conversa['lead_id'])) registrarTimeline($pdo, (int) $conversa['lead_id'], 'whatsapp_human_lock', $descricao, $usuario);
    return buscarConversaWhatsapp($pdo, $conversationId) ?? $conversa;
}

/** @return array<string,mixed> */
function enviarMensagemHumanaWhatsapp(PDO $pdo, int $conversationId, string $texto, array $usuario): array
{
    $texto = trim($texto);
    if ($texto === '' || mb_strlen($texto) > WHATSAPP_MAX_MESSAGE_LENGTH) throw new InvalidArgumentException('Mensagem inválida.');
    $conversa = alterarTravaHumanaWhatsapp($pdo, $conversationId, 'assume', $usuario);
    try {
        $resposta = enviarWhatsappProvider($conversa, $conversa, $texto);
        $providerId = whatsappProviderMessageId($resposta);
        $status = 'sent'; $erro = null;
    } catch (Throwable $e) {
        $providerId = 'dnb-failed-' . bin2hex(random_bytes(10));
        $status = 'failed'; $erro = mb_substr($e->getMessage(), 0, 1000);
    }
    $stmt = $pdo->prepare("INSERT INTO whatsapp_messages (provider_message_id,instance_id,conversation_id,lead_id,direction,sender_type,sender_user_id,message_body,status,error_message,created_at,processed_at)
        VALUES (?,?,?,?, 'outgoing','human',?,?,?, ?,NOW(),NOW())");
    $stmt->execute([$providerId, (int) $conversa['instance_id'], $conversationId, $conversa['lead_id'] ?: null, (int) $usuario['id'], $texto, $status, $erro]);
    $messageId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE whatsapp_conversations SET last_message_preview=?,last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([mb_substr($texto, 0, 500), $conversationId]);
    if ($status === 'failed') throw new WhatsappProviderException($erro ?: 'Falha no envio da mensagem.');
    return ['id' => $messageId, 'provider_message_id' => $providerId, 'status' => $status, 'message_body' => $texto, 'created_at' => date('Y-m-d H:i:s')];
}

function vincularConversaWhatsappAoLead(PDO $pdo, int $conversationId, int $leadId): void
{
    $stmt = $pdo->prepare('SELECT contact_id,lead_id FROM whatsapp_conversations WHERE id=?');
    $stmt->execute([$conversationId]);
    $registro = $stmt->fetch();
    $contactId = (int) ($registro['contact_id'] ?? 0);
    if (!$contactId) throw new WhatsappServiceException('Conversa de WhatsApp não encontrada.');
    if (!empty($registro['lead_id']) && (int) $registro['lead_id'] !== $leadId) {
        throw new WhatsappServiceException('Esta conversa já está vinculada a outro lead.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE whatsapp_contacts SET lead_id=?,updated_at=NOW() WHERE id=?')->execute([$leadId, $contactId]);
        $pdo->prepare('UPDATE whatsapp_conversations SET lead_id=?,updated_at=NOW() WHERE id=?')->execute([$leadId, $conversationId]);
        $pdo->prepare('UPDATE whatsapp_messages SET lead_id=? WHERE conversation_id=?')->execute([$leadId, $conversationId]);
        $pdo->prepare("UPDATE whatsapp_messages SET status='queued',processed_at=NULL WHERE conversation_id=? AND direction='incoming' AND status IN ('received','ignored')")->execute([$conversationId]);
        $stmt = $pdo->prepare("SELECT MIN(id) first_id,MAX(id) last_id,MIN(created_at) first_at,MAX(created_at) last_at FROM whatsapp_messages WHERE conversation_id=? AND direction='incoming' AND status='queued'");
        $stmt->execute([$conversationId]); $datas = $stmt->fetch();
        if (!empty($datas['last_at'])) {
            $pdo->prepare("INSERT INTO whatsapp_queue (conversation_id,first_message_id,last_message_id,first_message_at,last_message_at,available_at,status,attempts,created_at,updated_at)
                VALUES (?,?,?,?,?,NOW(),'pending',0,NOW(),NOW()) ON DUPLICATE KEY UPDATE first_message_id=VALUES(first_message_id),last_message_id=VALUES(last_message_id),first_message_at=VALUES(first_message_at),last_message_at=VALUES(last_message_at),
                available_at=NOW(),status='pending',attempts=0,locked_at=NULL,processed_at=NULL,error_message=NULL,ai_interaction_id=NULL,updated_at=NOW()")
                ->execute([$conversationId, $datas['first_id'], $datas['last_id'], $datas['first_at'], $datas['last_at']]);
            interromperFollowupPorMensagemRecebida($pdo, $leadId, (int) $datas['last_id'], (string) $datas['last_at']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    registrarTimeline($pdo, $leadId, 'whatsapp_link', 'Conversa WhatsApp #' . $conversationId . ' vinculada ao lead.');
}

/** @return array<string,mixed> */
function processarItemFilaWhatsapp(PDO $pdo, array $fila): array
{
    $conversationId = (int) $fila['conversation_id'];
    $stmt = $pdo->prepare("SELECT wc.*,ct.phone,ct.display_name,wi.external_instance_id,wi.name,wi.provider,
        o.numero,o.cliente,o.linha,o.funil,o.etapa,o.status lead_status,o.valor,o.obs,o.assigned_to_user_id lead_owner_id
        FROM whatsapp_conversations wc JOIN whatsapp_contacts ct ON ct.id=wc.contact_id
        JOIN whatsapp_instances wi ON wi.id=wc.instance_id LEFT JOIN orcamentos o ON o.id=wc.lead_id WHERE wc.id=?");
    $stmt->execute([$conversationId]); $conversa = $stmt->fetch();
    if (!$conversa) throw new WhatsappServiceException('Conversa da fila não encontrada.');
    if (whatsappTravaHumanaAtiva($conversa)) {
        $pdo->prepare("UPDATE whatsapp_messages SET status='ignored',processed_at=NOW() WHERE conversation_id=? AND direction='incoming' AND status='queued'")->execute([$conversationId]);
        return ['status' => 'skipped', 'reason' => 'Human Lock ativo; IA pausada.'];
    }
    if (empty($conversa['lead_id'])) {
        $pdo->prepare("UPDATE whatsapp_messages SET status='ignored',processed_at=NOW() WHERE conversation_id=? AND direction='incoming' AND status='queued'")->execute([$conversationId]);
        return ['status' => 'skipped', 'reason' => 'Conversa ainda não vinculada a um lead.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE conversation_id=? AND direction='incoming' AND status='queued' AND id<=? ORDER BY id");
    $stmt->execute([$conversationId, (int) $fila['last_message_id']]); $mensagens = $stmt->fetchAll();
    if (!$mensagens) return ['status' => 'completed', 'reason' => 'Nenhuma mensagem pendente.'];
    $texto = agruparMensagensWhatsapp($mensagens);
    if ($texto === '') throw new WhatsappServiceException('Buffer sem conteúdo processável.');

    $interacaoId = (int) ($fila['ai_interaction_id'] ?? 0);
    if ($interacaoId) {
        $stmt = $pdo->prepare("SELECT response_text,route_name,lead_score,score_justification,guardrail_status,guardrail_reason,escalated FROM ai_interactions WHERE id=? AND status='completed'");
        $stmt->execute([$interacaoId]); $existente = $stmt->fetch();
        if ($existente) {
            $resposta = [
                'response' => (string) $existente['response_text'], 'route' => (string) $existente['route_name'],
                'score' => $existente['lead_score'] !== null ? (int) $existente['lead_score'] : null,
                'score_justification' => (string) $existente['score_justification'], 'guardrail_status' => (string) $existente['guardrail_status'],
                'guardrail_reason' => (string) $existente['guardrail_reason'], 'escalated' => !empty($existente['escalated']), 'reason_blocked' => '',
            ];
        }
    }

    if (empty($resposta)) {
        $solicitanteId = (int) ($conversa['lead_owner_id'] ?? 0);
        if (!$solicitanteId) {
            $solicitanteId = idsUsuariosComPapeis($pdo, [PAPEL_ADMINISTRADOR])[0] ?? 0;
        }
        if (!$solicitanteId) throw new WhatsappServiceException('Nenhum usuário ativo disponível para registrar o processamento da IA.');
        $solicitante = buscarUsuarioComContextoCargos($pdo, $solicitanteId);
        if (!$solicitante) throw new WhatsappServiceException('Usuário responsável pelo processamento não está ativo.');
        $interacaoId = registrarInteracaoIa($pdo, (int) $conversa['lead_id'], $solicitanteId, $texto);
        $pdo->prepare('UPDATE whatsapp_queue SET ai_interaction_id=? WHERE id=?')->execute([$interacaoId, (int) $fila['id']]);
        $payload = [
            'contract_version' => '1.0', 'correlation_id' => bin2hex(random_bytes(16)), 'source' => 'dnb-crm-whatsapp-cpanel',
            'lead' => ['id'=>(int)$conversa['lead_id'],'number'=>$conversa['numero'],'customer'=>$conversa['cliente'],'product_line'=>$conversa['linha'],
                'funnel'=>$conversa['funil'],'stage'=>$conversa['etapa'],'status'=>$conversa['lead_status'],'value'=>(float)$conversa['valor'],'notes'=>$conversa['obs']],
            'request' => ['message'=>$texto,'channel'=>'whatsapp','conversation_id'=>$conversationId],
            'requested_by' => ['id'=>$solicitanteId,'name'=>$solicitante['nome'],'role'=>$solicitante['role'],'roles'=>$solicitante['papeis'],'cargos'=>$solicitante['cargo_titles']],
        ];
        $resposta = enviarParaIaVps($payload);
        concluirInteracaoIa($pdo, $interacaoId, $resposta);
        if ($resposta['score'] !== null) salvarLeadScore($pdo, (int) $conversa['lead_id'], (int) $resposta['score'], (string) $resposta['score_justification']);
        registrarTimeline($pdo, (int) $conversa['lead_id'], 'whatsapp_ai', 'IA processou ' . count($mensagens) . ' mensagem(ns) agrupada(s) do WhatsApp.');
    }

    if ($resposta['escalated'] || $resposta['guardrail_status'] === 'blocked') {
        $stmt = $pdo->prepare('SELECT id FROM support_cases WHERE ai_interaction_id=? ORDER BY id LIMIT 1');
        $stmt->execute([$interacaoId]); $casoId = (int) $stmt->fetchColumn();
        if (!$casoId) {
            $stmt = $pdo->prepare("INSERT INTO support_cases (lead_id,ai_interaction_id,reason_blocked,customer_query,status,created_at,updated_at) VALUES (?,?,?,?, 'open',NOW(),NOW())");
            $stmt->execute([(int) $conversa['lead_id'], $interacaoId, $resposta['reason_blocked'] ?: $resposta['guardrail_reason'], $texto]);
            $casoId = (int) $pdo->lastInsertId();
            notificarTransbordo($pdo, $casoId, (int) $conversa['lead_id'], (string) $conversa['cliente'], $conversa['lead_owner_id'] ? (int) $conversa['lead_owner_id'] : null);
            registrarTimeline($pdo, (int) $conversa['lead_id'], 'whatsapp_handoff', 'IA pausada e conversa encaminhada ao caso #' . $casoId . '.');
        }
        $pdo->prepare('UPDATE whatsapp_conversations SET ai_paused=1,human_locked_at=COALESCE(human_locked_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$conversationId]);
    }

    $podeResponderAutomaticamente = empty($resposta['escalated']) && $resposta['guardrail_status'] !== 'blocked';
    if ($podeResponderAutomaticamente && trim((string) $resposta['response']) !== '') {
        $provider = enviarWhatsappProvider($conversa, $conversa, (string) $resposta['response']);
        $providerId = whatsappProviderMessageId($provider);
        $stmt = $pdo->prepare("INSERT INTO whatsapp_messages (provider_message_id,instance_id,conversation_id,lead_id,direction,sender_type,message_body,status,created_at,processed_at)
            VALUES (?,?,?,?, 'outgoing','ai',?,'sent',NOW(),NOW()) ON DUPLICATE KEY UPDATE status='sent'");
        $stmt->execute([$providerId, (int) $conversa['instance_id'], $conversationId, (int) $conversa['lead_id'], (string) $resposta['response']]);
        $pdo->prepare('UPDATE whatsapp_conversations SET last_message_preview=?,last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([mb_substr((string)$resposta['response'],0,500),$conversationId]);
    }
    $ids = array_map('intval', array_column($mensagens, 'id'));
    $pdo->prepare("UPDATE whatsapp_messages SET status='processed',processed_at=NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
    return ['status' => 'completed', 'interaction_id' => $interacaoId, 'messages' => count($mensagens)];
}

/** @return array<string,int> */
function processarFilaWhatsapp(PDO $pdo, int $limite = 10): array
{
    $limite = max(1, min(50, $limite));
    $pdo->exec("UPDATE whatsapp_queue SET status='pending',locked_at=NULL,error_message='Lock expirado; item reagendado.' WHERE status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
    $ids = $pdo->query("SELECT id FROM whatsapp_queue WHERE status='pending' AND available_at<=NOW() ORDER BY available_at,id LIMIT {$limite}")->fetchAll();
    $resultado = ['processed'=>0,'completed'=>0,'skipped'=>0,'failed'=>0];
    foreach ($ids as $linha) {
        $filaId = (int) $linha['id'];
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_queue WHERE id=? AND status='pending' AND available_at<=NOW() FOR UPDATE");
        $stmt->execute([$filaId]); $fila = $stmt->fetch();
        if (!$fila) { $pdo->commit(); continue; }
        $pdo->prepare("UPDATE whatsapp_queue SET status='processing',attempts=attempts+1,locked_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=?")->execute([$filaId]);
        $pdo->commit();
        $resultado['processed']++;
        try {
            $item = processarItemFilaWhatsapp($pdo, $fila);
            $status = in_array($item['status'], ['completed','skipped'], true) ? $item['status'] : 'completed';
            $stmt = $pdo->prepare("UPDATE whatsapp_queue SET status=?,processed_at=NOW(),locked_at=NULL,error_message=?,updated_at=NOW()
                WHERE id=? AND status='processing' AND last_message_id=?");
            $stmt->execute([$status, mb_substr((string)($item['reason'] ?? ''),0,1000), $filaId, $fila['last_message_id']]);
            $resultado[$status]++;
        } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT ai_interaction_id FROM whatsapp_queue WHERE id=?');
            $stmt->execute([$filaId]); $interacaoAtual = (int) $stmt->fetchColumn();
            if ($interacaoAtual) {
                $stmt = $pdo->prepare('SELECT status FROM ai_interactions WHERE id=?');
                $stmt->execute([$interacaoAtual]);
                if ($stmt->fetchColumn() === 'processing') falharInteracaoIa($pdo, $interacaoAtual, $e->getMessage());
            }
            $tentativas = ((int) $fila['attempts']) + 1;
            $status = $tentativas >= 3 ? 'failed' : 'pending';
            $atraso = min(300, 15 * max(1, $tentativas));
            $stmt = $pdo->prepare("UPDATE whatsapp_queue SET status=?,available_at=DATE_ADD(NOW(),INTERVAL {$atraso} SECOND),locked_at=NULL,error_message=?,updated_at=NOW()
                WHERE id=? AND status='processing' AND last_message_id=?");
            $stmt->execute([$status, mb_substr($e->getMessage(),0,1000), $filaId, $fila['last_message_id']]);
            $resultado['failed']++;
            error_log('DNB WhatsApp queue #' . $filaId . ': ' . $e->getMessage());
        }
    }
    return $resultado;
}
