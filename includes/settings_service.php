<?php
declare(strict_types=1);
/**
 * Configurações de integração editáveis pela tela, guardadas em system_integrations.
 *
 * Precedência de leitura: banco → constante de whatsapp.local.php/config.php → getenv().
 * Assim o deploy não interrompe nada: enquanto a tabela estiver vazia o CRM
 * continua usando exatamente os valores que já estavam no servidor, e cada campo
 * migra para o banco no momento em que for salvo pela tela.
 *
 * Segredos são cifrados em AES-256-GCM com chave derivada de DNB_CONFIG_KEY, que
 * vive fora do banco. Sem essa chave o serviço se recusa a gravar segredos.
 */

require_once __DIR__ . '/db.php';

class SettingsServiceException extends RuntimeException {}

const INTEGRACAO_SERVICOS = ['whatsapp', 'ai_vps', 'rdstation_marketing', 'rdstation_crm'];

/** Prefixo que marca um valor cifrado, para distinguir de um valor legado em texto. */
const INTEGRACAO_PREFIXO_CIFRA = 'enc:v1:';

function integracaoChaveMestra(): string
{
    static $chave = null;
    if ($chave !== null) return $chave;
    $bruta = '';
    if (defined('DNB_CONFIG_KEY')) $bruta = trim((string) constant('DNB_CONFIG_KEY'));
    if ($bruta === '') $bruta = trim((string) (getenv('DNB_CONFIG_KEY') ?: ''));
    return $chave = $bruta;
}

function configKeyDisponivel(): bool
{
    return integracaoChaveMestra() !== '' && function_exists('openssl_encrypt');
}

/** Deriva a chave binária de 32 bytes usada pelo AES-256-GCM. */
function integracaoChaveDerivada(): string
{
    $mestra = integracaoChaveMestra();
    if ($mestra === '') throw new SettingsServiceException('DNB_CONFIG_KEY não está definida no servidor.');
    return hash_hkdf('sha256', $mestra, 32, 'dnb-crm-system-integrations');
}

function cifrarSegredo(string $valor): string
{
    if (!configKeyDisponivel()) {
        throw new SettingsServiceException('Não é possível gravar segredos sem DNB_CONFIG_KEY e a extensão openssl.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cifra = openssl_encrypt($valor, 'aes-256-gcm', integracaoChaveDerivada(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cifra === false) throw new SettingsServiceException('Falha ao cifrar o valor da integração.');
    return INTEGRACAO_PREFIXO_CIFRA . base64_encode($iv . $tag . $cifra);
}

/**
 * Decifra um valor gravado. Valores sem o prefixo são tratados como texto legado
 * e devolvidos como estão, para não quebrar linhas inseridas manualmente.
 */
function decifrarSegredo(?string $guardado): string
{
    $guardado = (string) $guardado;
    if ($guardado === '') return '';
    if (!str_starts_with($guardado, INTEGRACAO_PREFIXO_CIFRA)) return $guardado;
    if (!configKeyDisponivel()) return '';

    $bruto = base64_decode(substr($guardado, strlen(INTEGRACAO_PREFIXO_CIFRA)), true);
    if ($bruto === false || strlen($bruto) < 29) return '';
    $iv = substr($bruto, 0, 12);
    $tag = substr($bruto, 12, 16);
    $cifra = substr($bruto, 28);
    $aberto = openssl_decrypt($cifra, 'aes-256-gcm', integracaoChaveDerivada(), OPENSSL_RAW_DATA, $iv, $tag);
    // GCM autentica: um valor adulterado ou cifrado com outra chave devolve false.
    return $aberto === false ? '' : $aberto;
}

function integracaoTabelaDisponivel(?PDO $pdo = null): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        // dbOpcional: um banco fora do ar devolve false e a leitura cai no arquivo
        // local, em vez de derrubar webhook e cron com um die().
        $pdo = $pdo ?? dbOpcional();
        if ($pdo === null) return $disponivel = false;
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_integrations'");
        return $disponivel = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable) {
        return $disponivel = false;
    }
}

/**
 * Carrega todas as linhas de um serviço de uma vez. Cacheado por request para não
 * repetir consulta em páginas que leem muitas chaves.
 *
 * @return array<string,string>
 */
function integracaoValores(string $servico, bool $recarregar = false): array
{
    static $cache = [];
    if ($recarregar) unset($cache[$servico]);
    if (isset($cache[$servico])) return $cache[$servico];

    $valores = [];
    try {
        $pdo = dbOpcional();
        if ($pdo !== null && integracaoTabelaDisponivel($pdo)) {
            $stmt = $pdo->prepare('SELECT config_key, config_value, is_secret FROM system_integrations WHERE service_name=?');
            $stmt->execute([$servico]);
            foreach ($stmt->fetchAll() as $linha) {
                $valores[$linha['config_key']] = !empty($linha['is_secret'])
                    ? decifrarSegredo($linha['config_value'])
                    : (string) $linha['config_value'];
            }
        }
    } catch (Throwable) {
        // Banco indisponível não pode derrubar webhook nem cron: cai no fallback.
        $valores = [];
    }
    return $cache[$servico] = $valores;
}

/**
 * Valor efetivo de uma configuração, na ordem banco → constante → getenv.
 * $constante é o nome usado hoje em whatsapp.local.php / config.php / ambiente.
 */
function integracaoValor(string $servico, string $chave, string $constante = '', string $padrao = ''): string
{
    $doBanco = integracaoValores($servico)[$chave] ?? '';
    if ($doBanco !== '') return $doBanco;

    if ($constante !== '') {
        if (defined($constante)) {
            $valor = trim((string) constant($constante));
            if ($valor !== '') return $valor;
        }
        $doAmbiente = getenv($constante);
        if ($doAmbiente !== false && trim((string) $doAmbiente) !== '') return trim((string) $doAmbiente);
    }
    return $padrao;
}

/** Indica se o valor efetivo veio do banco (e portanto é editável pela tela). */
function integracaoVemDoBanco(string $servico, string $chave): bool
{
    return (integracaoValores($servico)[$chave] ?? '') !== '';
}

/**
 * Grava uma chave. Passar null em $valor remove a linha, fazendo a configuração
 * voltar a cair no arquivo local — é o caminho de rollback pela própria tela.
 */
function salvarIntegracao(PDO $pdo, string $servico, string $chave, ?string $valor, bool $segredo = false, ?int $usuarioId = null): void
{
    if (!in_array($servico, INTEGRACAO_SERVICOS, true)) {
        throw new SettingsServiceException('Serviço de integração desconhecido: ' . $servico);
    }
    if (!integracaoTabelaDisponivel($pdo)) {
        throw new SettingsServiceException('Instale a migração 20260810_system_integrations.sql antes de salvar integrações.');
    }

    if ($valor === null || $valor === '') {
        $pdo->prepare('DELETE FROM system_integrations WHERE service_name=? AND config_key=?')->execute([$servico, $chave]);
        integracaoValores($servico, true);
        return;
    }

    $guardado = $segredo ? cifrarSegredo($valor) : $valor;
    $pdo->prepare('INSERT INTO system_integrations (service_name, config_key, config_value, is_secret, updated_by_user_id)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), is_secret=VALUES(is_secret), updated_by_user_id=VALUES(updated_by_user_id)')
        ->execute([$servico, $chave, $guardado, $segredo ? 1 : 0, $usuarioId]);
    integracaoValores($servico, true);
}

/** Mostra só esquema e host de uma URL — o caminho pode conter identificadores. */
function integracaoUrlMascarada(string $url): string
{
    if ($url === '') return '—';
    $partes = parse_url($url);
    if ($partes === false || empty($partes['host'])) return 'valor inválido';
    return ($partes['scheme'] ?? 'https') . '://' . $partes['host'] . (isset($partes['path']) && $partes['path'] !== '/' ? '/…' : '');
}

/** Nunca devolvemos um segredo inteiro para a tela; só o suficiente para reconhecê-lo. */
function integracaoSegredoMascarado(string $valor): string
{
    $tamanho = strlen($valor);
    if ($tamanho === 0) return '';
    if ($tamanho <= 8) return str_repeat('•', $tamanho);
    return str_repeat('•', 8) . ' ' . substr($valor, -4);
}

/** Base HTTPS de onde o CRM está sendo servido, usada para pré-preencher callbacks. */
function integracaoBaseUrl(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return 'https://crm.dnbair.com.br';
    return 'https://' . rtrim($host, '/');
}
