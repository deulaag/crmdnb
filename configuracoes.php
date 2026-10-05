<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $provider = (string)($_POST['provider'] ?? '');
        if (!in_array($provider, ['rd_crm','rd_marketing','openai','cnpja'], true)) {
            throw new RuntimeException('Integração inválida.');
        }

        $current = integration_get($pdo, $provider);
        $secret = trim((string)($_POST['client_secret'] ?? ''));
        $access = trim((string)($_POST['access_token'] ?? ''));
        $refresh = trim((string)($_POST['refresh_token'] ?? ''));

        $extra = [];

        if ($provider === 'openai') {
            $extra['model'] = trim((string)($_POST['model'] ?? ''));
        }

        if ($provider === 'cnpja') {
            $strategy = strtoupper(trim((string)($_POST['strategy'] ?? 'ONLINE')));
            if (!in_array($strategy, ['ONLINE','CACHE_IF_FRESH','CACHE_IF_ERROR','CACHE'], true)) {
                $strategy = 'ONLINE';
            }

            $extra = [
                'strategy' => $strategy,
                'max_age' => max(1, min(3650, (int)($_POST['max_age'] ?? 1))),
                'max_stale' => max(1, min(3650, (int)($_POST['max_stale'] ?? 30))),
            ];
        }

        $data = [
            'client_id' => trim((string)($_POST['client_id'] ?? '')) ?: ($current['client_id'] ?? null),
            'client_secret' => $secret !== '' ? $secret : secret_decrypt($current['client_secret'] ?? ''),
            'access_token' => $access !== '' ? $access : secret_decrypt($current['access_token'] ?? ''),
            'refresh_token' => $refresh !== '' ? $refresh : secret_decrypt($current['refresh_token'] ?? ''),
            'is_active' => isset($_POST['is_active']),
            'extra' => $extra,
        ];

        integration_upsert($pdo, $provider, $data, (int)$user['id']);
        audit($pdo, (int)$user['id'], 'configure', 'integration', null, ['provider' => $provider]);
        flash('success', 'Configuração salva com credenciais protegidas.');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('configuracoes.php');
}

$providers = [
    'rd_crm' => ['RD Station CRM', 'OAuth para negociações, empresas, contatos e usuários.'],
    'rd_marketing' => ['RD Station Marketing', 'Base de leads, conversões e contexto de marketing.'],
    'openai' => ['ChatGPT / OpenAI', 'Assistência de IA acionada somente pelo backend PHP.'],
    'cnpja' => ['CNPJá · Receita Federal', 'Consulta CNPJ para preencher automaticamente os dados cadastrais do cliente.'],
];

$configs = [];
foreach (array_keys($providers) as $provider) {
    $configs[$provider] = integration_get($pdo, $provider);
}

$lines = $pdo->query(
    'SELECT pl.*,f.label funnel_label
     FROM product_lines pl
     JOIN funnels f ON f.funnel_key=pl.funnel_key
     ORDER BY pl.sort_order'
)->fetchAll();

page_header('Configurações','configuracoes');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">ADMINISTRAÇÃO</span>
        <h2>Integrações</h2>
        <p>As credenciais são informadas nesta tela e armazenadas criptografadas no banco.</p>
    </div>
</div>

<div class="integration-grid">
<?php foreach ($providers as $key => [$title,$desc]):
    $cfg = $configs[$key];
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true) ?: [];
?>
    <div class="card integration-card">
        <div class="card-title">
            <div>
                <span class="tag"><?= e(strtoupper($key)) ?></span>
                <h3><?= e($title) ?></h3>
                <p><?= e($desc) ?></p>
            </div>
            <span class="dot <?= !empty($cfg['is_active']) ? 'on' : '' ?>"></span>
        </div>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="provider" value="<?= e($key) ?>">

            <?php if (in_array($key, ['rd_crm','rd_marketing'], true)): ?>
                <label>
                    <span>Client ID</span>
                    <input name="client_id" value="<?= e($cfg['client_id'] ?? '') ?>">
                </label>
                <label>
                    <span>Client Secret</span>
                    <input name="client_secret" type="password" placeholder="<?= !empty($cfg['client_secret']) ? '•••••••• salvo' : 'informe o segredo' ?>">
                </label>
                <label>
                    <span>Access Token</span>
                    <input name="access_token" type="password" placeholder="<?= !empty($cfg['access_token']) ? '•••••••• salvo' : 'opcional / OAuth' ?>">
                </label>
                <label>
                    <span>Refresh Token</span>
                    <input name="refresh_token" type="password" placeholder="<?= !empty($cfg['refresh_token']) ? '•••••••• salvo' : 'opcional / OAuth' ?>">
                </label>

            <?php elseif ($key === 'openai'): ?>
                <label>
                    <span>API Key</span>
                    <input name="access_token" type="password" placeholder="<?= !empty($cfg['access_token']) ? '•••••••• salva' : 'API Key' ?>">
                </label>
                <label>
                    <span>Modelo</span>
                    <input name="model" value="<?= e($extra['model'] ?? '') ?>" placeholder="gpt-5.6-sol">
                </label>

            <?php elseif ($key === 'cnpja'): ?>
                <label>
                    <span>Chave de API CNPJá</span>
                    <input name="access_token" type="password" placeholder="<?= !empty($cfg['access_token']) ? '•••••••• salva' : 'cole a chave de API' ?>">
                </label>

                <label>
                    <span>Estratégia da consulta</span>
                    <select name="strategy">
                        <?php $strategy = strtoupper((string)($extra['strategy'] ?? 'ONLINE')); ?>
                        <option value="ONLINE" <?= $strategy==='ONLINE'?'selected':'' ?>>ONLINE · consultar a fonte agora</option>
                        <option value="CACHE_IF_FRESH" <?= $strategy==='CACHE_IF_FRESH'?'selected':'' ?>>CACHE_IF_FRESH · usar cache recente</option>
                        <option value="CACHE_IF_ERROR" <?= $strategy==='CACHE_IF_ERROR'?'selected':'' ?>>CACHE_IF_ERROR · cache com contingência</option>
                        <option value="CACHE" <?= $strategy==='CACHE'?'selected':'' ?>>CACHE · somente cache</option>
                    </select>
                </label>

                <div class="form-grid">
                    <label>
                        <span>Máx. idade do cache (dias)</span>
                        <input type="number" min="1" max="3650" name="max_age" value="<?= e((string)($extra['max_age'] ?? 1)) ?>">
                    </label>
                    <label>
                        <span>Máx. cache de contingência (dias)</span>
                        <input type="number" min="1" max="3650" name="max_stale" value="<?= e((string)($extra['max_stale'] ?? 30)) ?>">
                    </label>
                </div>

                <div class="install-note">
                    Para buscar os dados diretamente na fonte no momento da consulta, use <strong>ONLINE</strong>. Essa modalidade pode consumir créditos da CNPJá.
                </div>
            <?php endif; ?>

            <label class="toggle">
                <input type="checkbox" name="is_active" value="1" <?= !empty($cfg['is_active']) ? 'checked' : '' ?>>
                <span>Integração ativa</span>
            </label>

            <button class="btn btn-primary" type="submit">Salvar configuração</button>
        </form>
    </div>
<?php endforeach; ?>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">ESTRUTURA DO MODELO</span>
            <h3>Linhas de produto e funil automático</h3>
            <p>Itens estruturais trazidos do HTML, sem registros comerciais de demonstração.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Linha</th><th>Funil</th><th>Tipo</th><th>Agenciamento</th></tr></thead>
            <tbody>
            <?php foreach($lines as $line): ?>
                <tr>
                    <td><strong><?= e($line['name']) ?></strong></td>
                    <td><?= e($line['funnel_label']) ?></td>
                    <td><?= e($line['item_type']) ?></td>
                    <td><?= (int)$line['is_agency'] ? 'Sim' : 'Não' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php page_footer(); ?>
