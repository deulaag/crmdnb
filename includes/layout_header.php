<?php
/**
 * Shell responsivo da aplicação - UI DNB v3.2.
 * Espera (opcionais): $tabAtual (string), $mes (string YYYY-MM)
 */
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/notifications.php';
$tabAtual = $tabAtual ?? '';
$mes = $mes ?? mesAtual();
$usuario = usuarioLogado();

$gruposNavegacao = [
    [
        'id' => 'atendimento',
        'rotulo' => 'Atendimento',
        'itens' => [
            ['wpp', 'WhatsApp', 'chat.php', 'whatsapp', PAPEIS_CRM],
            ['cas', 'Casos', 'casos.php', 'cases', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_SUPORTE]],
        ],
    ],
    [
        'id' => 'crm',
        'rotulo' => 'CRM',
        'itens' => [
            ['painel', 'Painel', 'painel.php', 'dashboard', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
            ['orc', 'Orçamentos', 'orcamentos.php', 'quotes', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
            ['ped', 'Pedidos', 'pedidos.php', 'orders', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
            ['vis', 'Visitas', 'visitas.php', 'visits', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
        ],
    ],
    [
        'id' => 'agente-ia',
        'rotulo' => 'Agente de IA',
        'itens' => [
            ['ia', 'IA central', 'ia.php', 'ai', PAPEIS_CRM],
            ['cad', 'Follow-ups', 'cadencia.php', 'cadence', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
        ],
    ],
    [
        'id' => 'analise',
        'rotulo' => 'Análise',
        'itens' => [
            ['com', 'Comissões', 'comissoes.php', 'commissions', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR]],
            ['audit', 'Audit Log', 'auditoria.php', 'audit', [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_SUPORTE]],
        ],
    ],
    [
        'id' => 'gestao',
        'rotulo' => 'Gestão',
        'itens' => [
            ['usr', 'Usuários', 'usuarios.php', 'users', [PAPEL_ADMINISTRADOR]],
        ],
    ],
];

$itemConfiguracoes = ['cfg', 'Configurações', 'configuracoes.php', 'settings', [PAPEL_ADMINISTRADOR]];
$papeisUsuario = papeisAtuais();

$paginas = [
    'painel' => ['Visão geral', 'Acompanhe metas, desempenho e indicadores comerciais.'],
    'orc' => ['Orçamentos', 'Gerencie oportunidades e avance negociações no funil.'],
    'ped' => ['Pedidos', 'Acompanhe vendas, faturamento e entregas comerciais.'],
    'vis' => ['Visitas', 'Registre atividades de campo e novas prospecções.'],
    'cad' => ['Follow-ups', 'Desenhe fluxos visuais e acompanhe as cadências automáticas.'],
    'com' => ['Comissões', 'Consulte a apuração mensal por política comercial.'],
    'audit' => ['Auditoria de fluxos', 'Investigue execuções, falhas e o caminho percorrido por cada automação.'],
    'ia' => ['IA central', 'Roteie análises, aplique guardrails e qualifique oportunidades.'],
    'wpp' => ['WhatsApp', 'Atenda conversas dentro do seu escopo comercial com Human Lock.'],
    'cas' => ['Casos de atendimento', 'Faça a triagem dos atendimentos transbordados pela IA.'],
    'usr' => ['Gestão de usuários', 'Administre pessoas, múltiplos cargos, credenciais e equipes.'],
    'cfg' => ['Configurações', 'Administre políticas, usuários e dados do CRM.'],
];
[$tituloPagina, $descricaoPagina] = $paginas[$tabAtual] ?? ['CRM Comercial', 'Gestão comercial DNB em um só lugar.'];
$iniciais = $usuario ? mb_strtoupper(mb_substr(trim($usuario['nome']), 0, 1)) : 'D';
$notificacoes = $usuario ? listarNotificacoes(db(), (int) $usuario['id']) : [];
$notificacoesNaoLidas = $usuario ? contarNotificacoesNaoLidas(db(), (int) $usuario['id']) : 0;
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
<link rel="stylesheet" href="/assets/style.css?v=3.7.0">
</head>
<body class="dnb-body min-h-screen">
<a class="dnb-skip-link" href="#conteudo-principal">Pular para o conteúdo</a>

<div class="dnb-app" data-dnb-app>
  <div class="dnb-sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>
  <aside class="dnb-sidebar" id="dnb-sidebar" aria-label="Navegação principal">
    <div class="dnb-sidebar__brand">
      <img src="/assets/dnb-logo-blue.png" width="156" height="54" alt="DNB">
      <span>CRM Comercial</span>
    </div>

    <nav class="dnb-nav" aria-label="Módulos do CRM">
      <?php foreach ($gruposNavegacao as $grupo):
          $itensVisiveis = array_values(array_filter(
              $grupo['itens'],
              static fn(array $item): bool => (bool) array_intersect($papeisUsuario, $item[4])
          ));
          if (!$itensVisiveis) continue;
          $grupoId = 'dnb-nav-' . $grupo['id'];
      ?>
        <div class="dnb-nav__group" aria-labelledby="<?= e($grupoId) ?>">
          <span class="dnb-nav__eyebrow" id="<?= e($grupoId) ?>"><?= e($grupo['rotulo']) ?></span>
          <?php foreach ($itensVisiveis as [$k, $l, $href, $icone, $papeis]):
              $ativo = $tabAtual === $k;
              $qs = $mes ? ('?mes=' . urlencode($mes)) : '';
          ?>
            <a href="<?= e($href . $qs) ?>" class="dnb-nav__link<?= $ativo ? ' is-active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
              <?= dnbIcon($icone) ?>
              <span><?= e($l) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="dnb-sidebar__footer">
      <?php [$k, $l, $href, $icone, $papeis] = $itemConfiguracoes;
      if (array_intersect($papeisUsuario, $papeis)):
          $ativo = $tabAtual === $k;
      ?>
        <a href="<?= e($href) ?>" class="dnb-nav__link dnb-nav__link--footer<?= $ativo ? ' is-active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
          <?= dnbIcon($icone) ?>
          <span><?= e($l) ?></span>
        </a>
      <?php endif; ?>
      <div class="dnb-sidebar__meta">
        <div class="dnb-sidebar__status"><span></span>Sistema operacional</div>
        <small>CRM DNB v3.3</small>
      </div>
    </div>
  </aside>

  <div class="dnb-shell">
    <header class="dnb-topbar">
      <div class="dnb-topbar__title">
        <button class="dnb-icon-button dnb-menu-button" type="button" data-sidebar-open aria-controls="dnb-sidebar" aria-expanded="false" aria-label="Abrir menu">
          <?= dnbIcon('menu') ?>
        </button>
        <div>
          <span class="dnb-eyebrow">DNB CRM</span>
          <h1><?= e($tituloPagina) ?></h1>
          <p><?= e($descricaoPagina) ?></p>
        </div>
      </div>

      <div class="dnb-topbar__actions">
        <form method="get" class="dnb-period-filter">
          <label for="dnb-mes">Mês de referência</label>
          <input id="dnb-mes" type="month" name="mes" value="<?= e($mes) ?>" class="<?= INPUT_CLS ?>" onchange="this.form.submit()">
        </form>
        <?php if ($usuario): ?>
          <details class="dnb-notifications">
            <summary class="dnb-icon-button" aria-label="Notificações"><?= dnbIcon('bell') ?><?php if ($notificacoesNaoLidas): ?><span class="dnb-notification-badge"><?= min(99, $notificacoesNaoLidas) ?></span><?php endif; ?></summary>
            <div class="dnb-notifications__panel">
              <strong>Notificações</strong>
              <?php foreach ($notificacoes as $n): ?><a href="<?= e($n['link'] ?: '#') ?>" class="<?= $n['read_at'] ? '' : 'is-unread' ?>"><span><?= e($n['title']) ?></span><small><?= e($n['message']) ?></small></a><?php endforeach; ?>
              <?php if (!$notificacoes): ?><p>Nenhuma notificação.</p><?php endif; ?>
            </div>
          </details>
          <div class="dnb-user">
            <span class="dnb-user__avatar" aria-hidden="true"><?= e($iniciais) ?></span>
            <span class="dnb-user__identity"><strong><?= e($usuario['nome']) ?></strong><small><?= e(resumoTitulosCargos((array)($usuario['cargos']??[]))) ?></small></span>
            <a class="dnb-icon-button" href="/logout.php" title="Sair" aria-label="Sair da conta"><?= dnbIcon('logout') ?></a>
          </div>
        <?php endif; ?>
      </div>
    </header>

    <main class="dnb-content" id="conteudo-principal" tabindex="-1">
