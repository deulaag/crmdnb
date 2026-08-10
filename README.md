# DNB · CRM Comercial v3.1 — PHP + MySQL no cPanel

> **Ambiente oficial de produção:** servidor de hospedagem **cPanel**, com PHP 8.1+ e MySQL/MariaDB. O CRM continua em PHP puro, sem Composer ou SSH. A VPS é usada somente para o processamento externo de IA por HTTPS; ela não hospeda o CRM nem o banco de dados.

Porte completo do CRM para PHP puro + MySQL, pronto para hospedagem compartilhada via cPanel, File Manager ou FTP.

## Arquitetura atual

- **cPanel:** aplicação PHP, autenticação, RBAC, Live Chat do WhatsApp, webhooks, fila e banco MySQL/MariaDB.
- **VPS de IA:** RAG, roteamento multiagente, guardrails e lead scoring.
- **Comunicação:** o navegador chama o endpoint interno do CRM; o PHP no cPanel envia `POST /api/v1/ai/route-and-process` para a VPS com Bearer Token e HTTPS.
- **Dados oficiais:** usuários, oportunidades, scores, timeline, interações, casos, notificações e histórico do WhatsApp permanecem no MySQL do cPanel.

## O que mudou em relação à versão original

- **Login obrigatório.** O app original não tinha autenticação (dados "compartilhados" abertos). Nesta versão é preciso logar — veja `/setup.php` abaixo.
- **IA central via VPS.** O CRM não expõe credenciais no navegador: o cPanel autentica a chamada à VPS, registra resposta, score, guardrails, timeline e eventual transbordo para Suporte.
- **WhatsApp com RBAC.** O cPanel recebe webhooks, elimina duplicatas, agrupa mensagens por cinco segundos e apresenta somente as conversas autorizadas para cada perfil.
- **Human Lock.** Quando uma pessoa assume ou responde no Live Chat, a IA é pausada naquela conversa sem trocar o responsável comercial do lead.
- **Sincronização Salesforce por CSV** (em vez de CSV/XLSX). Exporte o relatório do Salesforce como CSV.
- **Cadência sem IA.** Os e-mails de follow-up são gerados por **modelo/template** (sem chamar a Anthropic), com botão "Copiar texto" — sem a integração de rascunho automático no Outlook (isso era exclusivo do Claude.ai).
- Todo o **motor de cálculo de comissão** (as 7 políticas: Vendedor IR, Vendedor Externo, Suporte IR, Interno Sênior, Representante, Consultor Sênior, Sem política) foi portado linha a linha para PHP (`includes/calc.php`) — os números devem bater exatamente com a versão original para os mesmos dados.

## Passo a passo de instalação no cPanel

1. **Banco de dados MySQL**
   - cPanel → *Bancos de Dados MySQL* → crie um banco (ex.: `dnbauto_crm`) e um usuário com senha forte, e associe o usuário ao banco com **todos os privilégios**.
   - Anote o host (geralmente `localhost`), nome do banco, usuário e senha.

2. **Importar o schema**
   - cPanel → *phpMyAdmin* → selecione o banco criado → aba **Importar** → escolha o arquivo `sql/schema.sql` deste projeto → Executar.
   - Isso cria as tabelas legadas e já popula a equipe (vendedores/políticas) e as linhas de produto.
   - Em seguida, ainda no phpMyAdmin, importe `sql/migrations/20260809_crm_ia_rbac.sql` para adicionar RBAC e IA.
   - Por último, importe `sql/migrations/20260809_whatsapp_rbac.sql` para adicionar WhatsApp, fila, buffer e Human Lock da v3.1.

   **Instalação existente:** não importe o schema completo. Faça backup do banco, confirme que `20260809_crm_ia_rbac.sql` já foi executado e importe somente `sql/migrations/20260809_whatsapp_rbac.sql` antes de publicar os arquivos da v3.1.

3. **Enviar os arquivos**
   - Envie **todo o conteúdo** desta pasta (exceto `sql/`, que fica só como referência — mas não tem problema subir também, está protegida por `.htaccess`) para a pasta pública do seu domínio/subdomínio no cPanel (geralmente `public_html` ou `public_html/crm`, dependendo de onde você quer o sistema).
   - Pode usar o *File Manager* do cPanel (upload do .zip e "Extrair") ou FTP.

4. **Configurar a conexão**
   - No servidor, copie `config.local.example.php` para `config.local.php` e preencha `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` com os dados do passo 1. O arquivo local não é enviado ao GitHub nem apagado pelo deploy FTPS.
   - A versão antiga mantinha a senha do banco no repositório. Troque essa senha no cPanel e use a nova somente em `config.local.php` antes de publicar a v3.0.

5. **Configurar a VPS de IA**
   - No cPanel, defina `DNB_AI_VPS_ENDPOINT` com a URL base HTTPS da VPS e `DNB_AI_VPS_TOKEN` com o Bearer Token.
   - Se o provedor não oferecer variáveis de ambiente, copie `config.local.example.php` para `config.local.php` no servidor e preencha os valores. Esse arquivo é ignorado pelo Git e preservado pelo deploy FTPS. Nunca publique o token no GitHub.
   - Confirme que a hospedagem permite conexões HTTPS de saída e possui a extensão `curl`; existe fallback por `file_get_contents` quando `allow_url_fopen` estiver habilitado.

6. **Configurar o WhatsApp no cPanel**
   - Copie `whatsapp.local.example.php` para `whatsapp.local.php` somente no servidor cPanel.
   - Preencha `DNB_WHATSAPP_WEBHOOK_TOKEN` com um segredo longo para autenticar o provedor no webhook.
   - Preencha `DNB_WHATSAPP_CRON_TOKEN` com outro segredo longo para chamadas HTTP do processador.
   - Preencha `DNB_WHATSAPP_SEND_URL` com o endpoint HTTPS de envio do seu provedor e `DNB_WHATSAPP_API_TOKEN` com o token da API.
   - Alternativamente, defina essas mesmas chaves como variáveis de ambiente no cPanel. O arquivo `whatsapp.local.php` é ignorado pelo Git e excluído do deploy FTPS.
   - Configure o provedor para enviar `POST` para `https://seu-dominio/api/webhooks/whatsapp/`, usando `Authorization: Bearer SEU_TOKEN` ou `X-DNB-Webhook-Token: SEU_TOKEN`.
   - O webhook aceita como contrato mínimo `instance_id`, `message_id`, `phone` e `text`; também reconhece campos aninhados comuns de provedores e aceita `lead_id` para vínculo automático. Sem `lead_id`, o Administrador pode vincular a conversa pela tela de detalhe do lead.
   - O adaptador padrão envia JSON com `instance_id`, `to`, `message` e, quando existir, `media_url`. Caso o contrato do provedor seja diferente, ajuste somente `includes/whatsapp_provider.php`.

7. **Criar o Cron Job do WhatsApp**
   - Em cPanel → **Cron Jobs**, agende a cada minuto:

     ```bash
     /usr/local/bin/php -q /home/USUARIO/public_html/api/internal/whatsapp/process_queue.php 20 >/dev/null 2>&1
     ```

   - Troque `USUARIO` e `public_html` pelo caminho real mostrado no cPanel. Se o executável PHP for diferente, use o caminho informado pela hospedagem.
   - O buffer é rearmado para cinco segundos após cada nova mensagem. Em PHP-FPM, o webhook tenta processá-lo em segundo plano após esses cinco segundos; o Cron Job recupera qualquer item pendente e funciona como garantia operacional.

8. **Criar o primeiro usuário**
   - Acesse `https://seudominio.com.br/setup.php` — essa página só funciona uma vez (enquanto não existir nenhum usuário). Crie o administrador (nome, e-mail, senha).
   - Depois disso, `/setup.php` se desativa sozinho e redireciona para o login.

9. **Entrar**
   - Acesse `https://seudominio.com.br/login.php` (ou `/index.php`, que redireciona) e entre com o e-mail/senha criados.

10. **(Opcional) Testar com dados de exemplo**
   - Aba **Configurações** → "Carregar simulação" — popula o mês de julho/2026 com o mesmo cenário de demonstração do app original. "Limpar tudo" zera a base e restaura a equipe padrão.

## Requisitos do servidor

- PHP **8.1 ou superior** com extensões `pdo_mysql`, `json`, `mbstring` e, preferencialmente, `curl`.
- MySQL 5.7+ / 8.0 ou MariaDB 10.2+ (suporte a coluna `JSON`).
- Não usa Composer nem bibliotecas externas — é PHP puro, funciona só com upload de arquivos.

## Estrutura de pastas

```
config.php              → configuração compartilhada e carregamento do arquivo local
config.local.example.php → modelo para `config.local.php` privado no cPanel
setup.php                → cria o primeiro usuário administrador
login.php / logout.php
painel.php                → dashboard (farol de metas, KPIs automáticos)
orcamentos.php             → funil de vendas, nova oportunidade, importações, conversão em pedido
cadencia.php                → follow-up por template + histórico de toques
pedidos.php                  → pedidos de venda e faturamento
visitas.php                   → visitas/prospecções + importação JSON
comissoes.php                  → relatório mensal (imprimir/PDF pelo navegador)
configuracoes.php                → políticas por pessoa, apuração, simulação, usuários
usuarios.php                     → CRUD de usuários, papéis e supervisão
ia.php                           → roteador central e histórico de IA
casos.php                        → triagem de transbordos para atendimento humano
lead.php                         → score, responsável e linha do tempo da oportunidade
chat.php                         → Live Chat do WhatsApp com RBAC e Human Lock
api/v1/ai/process.php            → endpoint interno autenticado para a VPS
api/webhooks/whatsapp/index.php  → recepção autenticada de mensagens do provedor
api/internal/whatsapp/process_queue.php → processador para Cron Jobs do cPanel
api/v1/whatsapp/                 → conversas, mensagens, atribuição e envio autorizados
includes/whatsapp_service.php    → idempotência, buffer, fila e integração com a IA
includes/whatsapp_provider.php   → adaptador isolado do provedor de WhatsApp
includes/                          → conexão PDO, autenticação, funções auxiliares, motor de comissão, seed
sql/schema.sql                      → schema legado + dados iniciais da equipe
sql/migrations/20260809_crm_ia_rbac.sql → atualização segura de instalações existentes
sql/migrations/20260809_whatsapp_rbac.sql → tabelas do WhatsApp da v3.1
assets/style.css                      → ajustes finos (o layout usa Tailwind via CDN)
```

## Papéis e escopo de acesso

| Papel | Escopo |
|---|---|
| `ADMINISTRADOR` | Todo o CRM, usuários, IA, casos e configurações. |
| `SUPERVISOR` | Leads, propostas, pedidos, visitas e indicadores dos vendedores ligados diretamente a ele; pode reatribuir leads dentro da equipe. |
| `VENDEDOR` | Somente registros com `assigned_to_user_id` igual ao próprio usuário; pode evoluir funil, registrar notas e gerar propostas. |
| `SUPORTE` | Casos transbordados pela IA, triagem e leads relacionados aos casos. |

O primeiro usuário criado em `setup.php` recebe automaticamente o papel `ADMINISTRADOR`. Os demais usuários e vínculos são mantidos em **Usuários**.

No Live Chat do WhatsApp, o `ADMINISTRADOR` vê todas as conversas; o `SUPERVISOR`, as conversas dos leads atribuídos à própria equipe; o `VENDEDOR`, apenas as dos próprios leads; e o `SUPORTE`, somente conversas ligadas a casos abertos ou em atendimento.

## Validação

Cada Pull Request executa validação de sintaxe no PHP 8.1 e testes de RBAC, IA, webhook, buffer e Human Lock. Para executar manualmente em um ambiente com PHP:

```bash
php tests/rbac_test.php
php tests/ai_payload_test.php
php tests/whatsapp_rbac_test.php
php tests/whatsapp_buffer_test.php
php tests/whatsapp_human_lock_test.php
php tests/whatsapp_webhook_test.php
```

## Atualização de produção no cPanel

O workflow `.github/workflows/deploy.yml` envia a `main` por FTPS ao cPanel. Arquivos `.sql`, `.md`, testes e `config.local.php` são excluídos do deploy, portanto a migração do banco é **manual pelo phpMyAdmin** e os segredos locais permanecem no servidor.

Ordem obrigatória para atualizar uma instalação existente:

1. Fazer backup do banco e dos arquivos atuais no cPanel.
2. Confirmar que `sql/migrations/20260809_crm_ia_rbac.sql` já foi instalado.
3. Baixar `sql/migrations/20260809_whatsapp_rbac.sql` do GitHub e executá-lo no phpMyAdmin.
4. Criar/revisar `whatsapp.local.php`, configurar o webhook no provedor e criar o Cron Job no cPanel.
5. Publicar/mesclar a versão v3.1 para disparar o deploy FTPS.
6. Testar uma mensagem recebida, o envio humano, o buffer e a visibilidade de cada perfil.

## Segurança

- Senhas de usuário ficam com hash `bcrypt` (`password_hash`/`password_verify` do PHP).
- As pastas `includes/` e `sql/` têm `.htaccess` bloqueando acesso direto pelo navegador.
- `config.php` e `config.local.php` têm acesso HTTP bloqueado pelo `.htaccess`; o PHP continua podendo carregá-los internamente.
- Depois de criar o administrador, considere apagar ou renomear `setup.php` (embora ele já se autodesative sozinho).
- Todo o site deve rodar em **HTTPS** — ative o certificado SSL grátis do cPanel (AutoSSL/Let's Encrypt) para o domínio.
- Webhook e processador HTTP recusam chamadas sem token; mensagens repetidas são eliminadas pelo ID do provedor.
- `whatsapp.local.php` nunca deve ser enviado ao GitHub nem compartilhado no navegador.

## Próximos passos possíveis (fora do escopo desta entrega)

- Enviar os e-mails de cadência automaticamente via **Resend** (API de envio transacional) em vez de só copiar o texto — dá para acrescentar um botão "Enviar" em `cadencia.php` chamando a API do Resend com uma chave configurada em `config.php`, quando vocês tiverem decidido esse fluxo.
- Acrescentar extração de PDF na VPS, mantendo as credenciais fora do navegador e usando as importações JSON existentes como contrato de entrada.
