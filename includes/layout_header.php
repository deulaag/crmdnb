<?php
/**
 * Shell responsivo da aplicação - UI DNB v4.2.
 * Referência visual: DNB_CRM_Teste_Local (piloto) + Manual da Marca DNB.
 * Espera (opcionais): $tabAtual (string), $mes (string YYYY-MM)
 */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/profile_service.php';

$tabAtual = $tabAtual ?? '';
$mes = $mes ?? mesAtual();
$usuario = usuarioLogado();
$papeisUsuario = papeisAtuais();

$navPrincipal = [
    ['painel', 'Painel', 'painel.php', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['neg', 'Negociações', 'negociacoes.php', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['ped', 'Pedidos', 'pedidos.php', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['vis', 'Visitas', 'visitas.php', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['cad', 'Cadência · Agente', 'cadencia.php', [PAPEL_ADMINISTRADOR]],
    ['com', 'Comissões', 'comissoes.php', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['cfg', 'Configurações', 'configuracoes.php', [PAPEL_ADMINISTRADOR]],
];

$navMais = [
    ['empresas', 'Empresas', 'empresas.php', 'users', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['contatos', 'Contatos', 'contatos.php', 'users', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
    ['ia', 'IA central', 'ia.php', 'ai', [PAPEL_ADMINISTRADOR]],
    ['wpp', 'WhatsApp', 'chat.php', 'whatsapp', [PAPEL_ADMINISTRADOR]],
    ['cas', 'Casos de atendimento', 'casos.php', 'cases', [PAPEL_ADMINISTRADOR]],
    ['auditpanel', 'Relatório do Painel', 'auditoria-relatorios.php', 'dashboard', [PAPEL_ADMINISTRADOR]],
    ['audit', 'Log de Fluxos', 'auditoria.php', 'audit', [PAPEL_ADMINISTRADOR]],
    ['usr', 'Usuários', 'usuarios.php', 'users', [PAPEL_ADMINISTRADOR]],
];

$paginas = [
    'painel' => ['Visão geral', 'Acompanhe metas, desempenho e tarefas comerciais.'],
    'empresas' => ['Empresas', 'Centralize clientes, contatos e todo o histórico comercial por empresa.'],
    'contatos' => ['Contatos', 'Organize pessoas, empresa vinculada, negociações, visitas e origem comercial.'],
    'neg' => ['Negociações', 'Gerencie o negócio comercial e relacione contatos, propostas, pedidos e visitas.'],
    'orc' => ['Orçamentos', 'Gerencie propostas vinculadas às negociações comerciais.'],
    'ped' => ['Pedidos', 'Acompanhe vendas, faturamento e entregas comerciais.'],
    'vis' => ['Visitas', 'Registre atividades de campo e novas prospecções.'],
    'cad' => ['Cadência · Agente', 'Acompanhe follow-ups e cadências automáticas.'],
    'com' => ['Comissões', 'Consulte a apuração mensal por política comercial.'],
    'auditpanel' => ['Relatório do Painel', 'Revise os negócios mês a mês e exporte cada competência.'],
    'audit' => ['Auditoria de fluxos', 'Investigue execuções, falhas e automações.'],
    'ia' => ['IA central', 'Roteie análises, aplique guardrails e qualifique oportunidades.'],
    'wpp' => ['WhatsApp', 'Atenda conversas dentro do CRM com Human Lock.'],
    'cas' => ['Casos de atendimento', 'Faça a triagem dos atendimentos transbordados pela IA.'],
    'usr' => ['Gestão de usuários', 'Administre pessoas, cargos, credenciais e equipes.'],
    'cfg' => ['Configurações', 'Administre políticas, integrações, tarefas, usuários e dados do CRM.'],
];
[$tituloPagina, $descricaoPagina] = $paginas[$tabAtual] ?? ['CRM Comercial', 'Gestão comercial DNB em um só lugar.'];

$iniciais = $usuario ? mb_strtoupper(mb_substr(trim($usuario['nome']), 0, 1)) : 'D';
$humorAtual = $usuario ? humorUsuario(db(), (int) $usuario['id']) : '';
$avatarAtual = '';
if ($usuario && perfilTabelasHumorDisponiveis(db())) {
    try {
        $stmtPerfilHeader = db()->prepare('SELECT avatar_path FROM user_profiles WHERE user_id=? LIMIT 1');
        $stmtPerfilHeader->execute([(int)$usuario['id']]);
        $avatarAtual = (string) ($stmtPerfilHeader->fetchColumn() ?: '');
    } catch (Throwable) { $avatarAtual = ''; }
}
$notificacoes = $usuario ? listarNotificacoes(db(), (int) $usuario['id']) : [];
$notificacoesNaoLidas = $usuario ? contarNotificacoesNaoLidas(db(), (int) $usuario['id']) : 0;

$navMaisVisivel = array_values(array_filter(
    $navMais,
    static fn(array $item): bool => (bool) array_intersect($papeisUsuario, $item[4])
));
$maisAtivo = in_array($tabAtual, array_column($navMaisVisivel, 0), true);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#003060">
<title><?= e($tituloPagina) ?> | <?= e(APP_NOME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Michroma&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/style.css?v=4.2.0">
</head>
<body class="dnb-body min-h-screen">
<a class="dnb-skip-link" href="#conteudo-principal">Pular para o conteúdo</a>

<div class="dnb-app dnb-pilot-app" data-dnb-app>
  <header class="dnb-pilot-header">
    <div class="dnb-pilot-header__main">
      <div class="dnb-pilot-brand">
        <img src="/assets/dnb-logo-blue.png" width="118" height="43" alt="DNB">
        <div class="dnb-pilot-brand__copy">
          <strong>DNB · CRM Comercial</strong>
          <span>Políticas de comissão vigentes · metas derivadas por conversão · dados compartilhados entre a equipe</span>
        </div>
      </div>

      <div class="dnb-pilot-header__actions">
        <form method="get" class="dnb-period-filter dnb-pilot-period">
          <label for="dnb-mes">Mês de referência</label>
          <input id="dnb-mes" type="month" name="mes" value="<?= e($mes) ?>" class="<?= INPUT_CLS ?>" onchange="this.form.submit()">
        </form>

        <?php if ($usuario): ?>
          <details class="dnb-notifications">
            <summary class="dnb-icon-button" aria-label="Notificações">
              <?= dnbIcon('bell') ?>
              <?php if ($notificacoesNaoLidas): ?><span class="dnb-notification-badge"><?= min(99, $notificacoesNaoLidas) ?></span><?php endif; ?>
            </summary>
            <div class="dnb-notifications__panel">
              <strong>Notificações</strong>
              <?php foreach ($notificacoes as $n): ?>
                <a href="<?= e($n['link'] ?: '#') ?>" class="<?= $n['read_at'] ? '' : 'is-unread' ?>">
                  <span><?= e($n['title']) ?></span>
                  <small><?= e($n['message']) ?></small>
                </a>
              <?php endforeach; ?>
              <?php if (!$notificacoes): ?><p>Nenhuma notificação.</p><?php endif; ?>
            </div>
          </details>

          <details class="dnb-pilot-user">
            <summary aria-label="Abrir perfil do usuário">
              <?php if ($avatarAtual !== ''): ?>
                <img src="<?= e($avatarAtual) ?>" class="dnb-user__avatar" alt="Foto de <?= e($usuario['nome']) ?>">
              <?php else: ?>
                <span class="dnb-user__avatar" aria-hidden="true"><?= e($iniciais) ?></span>
              <?php endif; ?>
              <span class="dnb-user__identity">
                <strong><?= e($usuario['nome']) ?><?= $humorAtual !== '' ? ' ' . e($humorAtual) : '' ?></strong>
                <small><?= e(resumoTitulosCargos((array)($usuario['cargos']??[]))) ?></small>
              </span>
              <span class="dnb-pilot-user__chevron">⌄</span>
            </summary>
            <div class="dnb-pilot-user__menu">
              <a href="/perfil.php">Meu perfil</a>
              <a href="/logout.php">Sair</a>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </div>

    <nav class="dnb-pilot-tabs" aria-label="Módulos do CRM">
      <?php foreach ($navPrincipal as [$k, $l, $href, $papeis]):
          if (!array_intersect($papeisUsuario, $papeis)) continue;
          $ativo = $tabAtual === $k || ($k === 'neg' && $tabAtual === 'orc');
          $qs = $mes ? ('?mes=' . urlencode($mes)) : '';
      ?>
        <a href="<?= e($href . $qs) ?>" class="dnb-pilot-tab<?= $ativo ? ' is-active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
          <?= e($l) ?>
        </a>
      <?php endforeach; ?>

      <?php if ($navMaisVisivel): ?>
        <details class="dnb-pilot-more<?= $maisAtivo ? ' is-active' : '' ?>">
          <summary class="dnb-pilot-tab">Mais</summary>
          <div class="dnb-pilot-more__menu">
            <?php foreach ($navMaisVisivel as [$k, $l, $href, $icone, $papeis]):
                $ativo = $tabAtual === $k;
                $qs = $mes ? ('?mes=' . urlencode($mes)) : '';
            ?>
              <a href="<?= e($href . $qs) ?>" class="<?= $ativo ? 'is-active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
                <?= dnbIcon($icone) ?>
                <span><?= e($l) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
    </nav>
  </header>

  <div class="dnb-shell dnb-pilot-shell">
    <h1 class="sr-only"><?= e($tituloPagina) ?></h1>
    <p class="sr-only"><?= e($descricaoPagina) ?></p>
    <main class="dnb-content dnb-pilot-content" id="conteudo-principal" tabindex="-1">