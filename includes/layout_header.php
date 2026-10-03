<?php
/**
 * Shell responsivo da aplicação - UI baseada somente no HTML piloto DNB CRM.
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
    'empresas' => ['Empresas', 'Centralize clientes, contatos e histórico comercial por empresa.'],
    'contatos' => ['Contatos', 'Organize pessoas, empresa vinculada, negociações e visitas.'],
    'neg' => ['Negociações', 'Gerencie o negócio comercial e relacione contatos, propostas, pedidos e visitas.'],
    'orc' => ['Orçamentos', 'Gerencie propostas vinculadas às negociações comerciais.'],
    'ped' => ['Pedidos', 'Acompanhe vendas, faturamento e entregas comerciais.'],
    'vis' => ['Visitas', 'Registre atividades de campo e novas prospecções.'],
    'cad' => ['Cadência · Agente', 'Acompanhe follow-ups e cadências automáticas.'],
    'com' => ['Comissões', 'Consulte a apuração mensal por política comercial.'],
    'auditpanel' => ['Relatório do Painel', 'Revise os negócios mês a mês e exporte cada competência.'],
    'audit' => ['Auditoria de fluxos', 'Investigue execuções, falhas e automações.'],
    'ia' => ['IA central', 'Roteie análises e qualifique oportunidades.'],
    'wpp' => ['WhatsApp', 'Atenda conversas dentro do CRM.'],
    'cas' => ['Casos de atendimento', 'Faça a triagem dos atendimentos.'],
    'usr' => ['Gestão de usuários', 'Administre pessoas, cargos, credenciais e equipes.'],
    'cfg' => ['Configurações', 'Administre políticas, integrações, tarefas, usuários e dados do CRM.'],
];
[$tituloPagina, $descricaoPagina] = $paginas[$tabAtual] ?? ['CRM Comercial', 'Gestão comercial DNB em um só lugar.'];

$notificacoes = $usuario ? listarNotificacoes(db(), (int) $usuario['id']) : [];
$notificacoesNaoLidas = $usuario ? contarNotificacoesNaoLidas(db(), (int) $usuario['id']) : 0;
$humorAtual = $usuario ? humorUsuario(db(), (int) $usuario['id']) : '';

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
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/style.css?v=4.3.0">
<link rel="stylesheet" href="/assets/crm-pilot-ui.css?v=4.3.0">
</head>
<body class="dnb-body min-h-screen">
<a class="dnb-skip-link" href="#conteudo-principal">Pular para o conteúdo</a>

<div class="dnb-app dnb-pilot-app" data-dnb-app>
  <div class="dnb-pilot-head px-5 pt-5 pb-3 flex items-end justify-between flex-wrap gap-3">
    <div>
      <div class="text-lg font-bold dnb-pilot-title">DNB · CRM Comercial</div>
      <div class="text-xs text-slate-500">Políticas de comissão vigentes · metas derivadas por conversão · dados compartilhados entre a equipe</div>
    </div>

    <div class="dnb-pilot-tools">
      <form method="get" class="dnb-pilot-month">
        <label for="dnb-mes">Mês de referência</label>
        <input id="dnb-mes" type="month" name="mes" value="<?= e($mes) ?>" class="<?= INPUT_CLS ?>" onchange="this.form.submit()">
      </form>

      <?php if ($usuario): ?>
        <details class="dnb-pilot-util dnb-notifications">
          <summary class="<?= BTN_S ?>" aria-label="Notificações">
            Notificações<?php if ($notificacoesNaoLidas): ?> · <?= min(99, $notificacoesNaoLidas) ?><?php endif; ?>
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

        <details class="dnb-pilot-util dnb-pilot-account">
          <summary class="<?= BTN_S ?>">
            <?= e($usuario['nome']) ?><?= $humorAtual !== '' ? ' ' . e($humorAtual) : '' ?>
          </summary>
          <div class="dnb-pilot-account__menu">
            <a href="/perfil.php">Meu perfil</a>
            <a href="/logout.php">Sair</a>
          </div>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <nav class="dnb-pilot-tabs px-5 pt-3 flex gap-2 flex-wrap" aria-label="Módulos do CRM">
    <?php foreach ($navPrincipal as [$k, $l, $href, $papeis]):
        if (!array_intersect($papeisUsuario, $papeis)) continue;
        $ativo = $tabAtual === $k || ($k === 'neg' && $tabAtual === 'orc');
        $qs = $mes ? ('?mes=' . urlencode($mes)) : '';
    ?>
      <a href="<?= e($href . $qs) ?>" class="dnb-pilot-tab<?= $ativo ? ' is-active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
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
              <span><?= e($l) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </nav>

  <div class="dnb-shell dnb-pilot-shell">
    <h1 class="sr-only"><?= e($tituloPagina) ?></h1>
    <p class="sr-only"><?= e($descricaoPagina) ?></p>
    <main class="dnb-content dnb-pilot-content p-5 max-w-6xl" id="conteudo-principal" tabindex="-1">