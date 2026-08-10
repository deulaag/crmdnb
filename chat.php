<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/whatsapp_service.php';
require_once __DIR__ . '/includes/helpers.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$conversas = listarConversasWhatsapp($pdo);
$conversationId = (int) ($_GET['conversation'] ?? ($conversas[0]['id'] ?? 0));
$conversa = $conversationId ? buscarConversaWhatsapp($pdo, $conversationId) : null;
if (!$conversa && $conversas) {
    $conversationId = (int) $conversas[0]['id'];
    $conversa = buscarConversaWhatsapp($pdo, $conversationId);
}
$mensagens = $conversa ? listarMensagensWhatsapp($pdo, $conversationId, 0, 300) : [];
if ($conversa) $pdo->prepare('UPDATE whatsapp_conversations SET unread_count=0 WHERE id=?')->execute([$conversationId]);
$ultimoId = $mensagens ? (int) end($mensagens)['id'] : 0;
$usuario = usuarioLogado();
$podeAlterarTrava = $conversa ? usuarioPodeAlterarTravaWhatsapp($conversa, $usuario) : false;
$tabAtual = 'wpp';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="dnb-whatsapp" data-whatsapp-chat data-conversation-id="<?= $conversationId ?>" data-csrf="<?= e(csrfToken()) ?>" data-last-message-id="<?= $ultimoId ?>">
  <aside class="dnb-chat-list" aria-label="Conversas do WhatsApp">
    <div class="dnb-chat-list__header">
      <div><span class="dnb-eyebrow">Live Chat</span><h2>Conversas</h2></div>
      <span class="dnb-chat-count"><?= count($conversas) ?></span>
    </div>
    <div class="dnb-chat-list__items">
      <?php foreach ($conversas as $item):
        $nome = $item['display_name'] ?: $item['phone'];
        $ativa = (int) $item['id'] === $conversationId;
      ?>
        <a class="dnb-chat-contact<?= $ativa ? ' is-active' : '' ?>" href="/chat.php?conversation=<?= (int) $item['id'] ?>"<?= $ativa ? ' aria-current="page"' : '' ?>>
          <span class="dnb-chat-contact__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nome, 0, 1))) ?></span>
          <span class="dnb-chat-contact__body">
            <span><strong><?= e($nome) ?></strong><time><?= e($item['last_message_at'] ? substr($item['last_message_at'], 11, 5) : '') ?></time></span>
            <small><?= e($item['lead_customer'] ?: 'Sem lead vinculado') ?></small>
            <p><?= e($item['last_message_preview'] ?: 'Conversa iniciada') ?></p>
          </span>
          <?php if ((int) $item['unread_count'] > 0): ?><b class="dnb-chat-unread"><?= min(99, (int) $item['unread_count']) ?></b><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$conversas): ?><div class="dnb-empty-state"><h2>Nenhuma conversa</h2><p>As mensagens autorizadas pelo seu perfil aparecerão aqui.</p></div><?php endif; ?>
    </div>
  </aside>

  <section class="dnb-chat-panel">
    <?php if ($conversa):
      $nomeContato = $conversa['display_name'] ?: $conversa['phone'];
      $travaAtiva = whatsappTravaHumanaAtiva($conversa);
      $atendenteAtribuido = !empty($conversa['assigned_to_user_id']);
    ?>
      <header class="dnb-chat-panel__header">
        <div>
          <span class="dnb-eyebrow"><?= e($conversa['instance_name']) ?></span>
          <h2><?= e($nomeContato) ?></h2>
          <p>
            <?php if ($conversa['lead_id']): ?><a href="/lead.php?id=<?= (int) $conversa['lead_id'] ?>">Lead #<?= (int) $conversa['lead_id'] ?> · <?= e($conversa['lead_customer']) ?></a><?php else: ?>Sem lead vinculado<?php endif; ?>
            · Responsável comercial: <?= e($conversa['lead_owner_name'] ?: 'não atribuído') ?>
          </p>
        </div>
        <div class="dnb-chat-lock" data-chat-lock>
          <?= $travaAtiva ? tag('IA pausada · ' . ($conversa['human_assignee_name'] ?: 'aguardando atendimento'), 'amber') : tag('IA ativa', 'green') ?>
          <?php if ($podeAlterarTrava): ?>
            <button type="button" class="<?= $atendenteAtribuido ? BTN_S : BTN_P ?>" data-chat-assign="<?= $atendenteAtribuido ? 'release' : 'assume' ?>">
              <?= $atendenteAtribuido ? 'Liberar atendimento' : 'Assumir atendimento' ?>
            </button>
          <?php endif; ?>
        </div>
      </header>

      <div class="dnb-chat-messages" data-chat-messages aria-live="polite" aria-label="Histórico da conversa">
        <?php foreach ($mensagens as $mensagem): ?>
          <article class="dnb-chat-message dnb-chat-message--<?= e($mensagem['direction']) ?>" data-message-id="<?= (int) $mensagem['id'] ?>">
            <div>
              <?php if ($mensagem['message_body']): ?><p><?= nl2br(e($mensagem['message_body'])) ?></p><?php endif; ?>
              <?php if ($mensagem['media_url']): ?><a href="<?= e($mensagem['media_url']) ?>" target="_blank" rel="noopener noreferrer">Abrir mídia recebida</a><?php endif; ?>
              <small><?= e($mensagem['sender_user_name'] ?: ($mensagem['sender_type'] === 'ai' ? 'IA DNB' : ($mensagem['direction'] === 'incoming' ? $nomeContato : 'Sistema'))) ?> · <?= e(substr($mensagem['created_at'], 0, 16)) ?> · <?= e($mensagem['status']) ?></small>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if (!$mensagens): ?><div class="dnb-empty-state" data-chat-empty><p>Nenhuma mensagem nesta conversa.</p></div><?php endif; ?>
      </div>

      <form class="dnb-chat-composer" data-chat-send-form>
        <label for="dnb-chat-message">Responder pelo WhatsApp</label>
        <div>
          <textarea id="dnb-chat-message" name="message" maxlength="<?= WHATSAPP_MAX_MESSAGE_LENGTH ?>" rows="2" class="<?= INPUT_CLS ?>" required placeholder="Digite a mensagem. O envio ativa o Human Lock automaticamente."></textarea>
          <button class="<?= BTN_P ?>" type="submit">Enviar</button>
        </div>
        <small data-chat-status>Atualização automática ativa.</small>
      </form>
    <?php else: ?>
      <div class="dnb-empty-state dnb-chat-panel__empty"><h2>Selecione uma conversa</h2><p>O histórico será exibido aqui conforme seu perfil de acesso.</p></div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
