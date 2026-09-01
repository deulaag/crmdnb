# DNB · CRM Comercial v3.3 — PHP + MySQL no cPanel

> **Ambiente oficial de produção:** servidor de hospedagem **cPanel**, com PHP 8.1+ e MySQL/MariaDB. O CRM continua em PHP puro, sem Composer ou SSH. A VPS é usada somente para o processamento externo de IA por HTTPS; ela não hospeda o CRM nem o banco de dados.

Porte completo do CRM para PHP puro + MySQL, pronto para hospedagem compartilhada via cPanel, File Manager ou FTP.

## Arquitetura atual

- **cPanel:** aplicação PHP, autenticação, RBAC, Live Chat do WhatsApp, webhooks, filas, motor de follow-up por Cron Job e banco MySQL/MariaDB.
- **VPS de IA:** RAG, roteamento multiagente, guardrails, lead scoring e geração contextual de follow-up.
- **Comunicação:** o PHP no cPanel chama `POST /api/v1/ai/route-and-process` e `POST /api/v1/ai/generate-followup` na VPS com Bearer Token e HTTPS.
- **Dados oficiais:** pessoas, usuários, cargos, oportunidades, scores, timeline, interações, casos, notificações, regras de follow-up e histórico do WhatsApp permanecem no MySQL do cPanel.

## O que mudou em relação à versão original

- **Login obrigatório.** O app original não tinha autenticação (dados "compartilhados" abertos). Nesta versão é preciso logar — veja `/setup.php` abaixo.
- **IA central via VPS.** O CRM não expõe credenciais no navegador: o cPanel autentica a chamada à VPS, registra resposta, score, guardrails, timeline e eventual transbordo para Suporte.
- **WhatsApp com RBAC.** O cPanel recebe webhooks, elimina duplicatas, agrupa mensagens por cinco segundos e apresenta somente as conversas autorizadas para cada perfil.
- **Human Lock.** Quando uma pessoa assume ou responde no Live Chat, a IA é pausada naquela conversa sem trocar o responsável comercial do lead.
- **Follow-up automático com IA.** Um Cron Job seleciona leads inativos conforme etapa, score e limite de tentativas; a VPS gera a mensagem e o cPanel a envia pelo WhatsApp com idempotência e auditoria.
- **Interrupção imediata.** Qualquer mensagem recebida do lead pausa a cadência automática; Human Lock, pausa manual e guardrail bloqueado também impedem novos envios.
- **Múltiplos cargos por pessoa.** O acesso é derivado dos vínculos em `user_cargos`; uma pessoa pode acumular funções comerciais, de supervisão e de suporte.
- **Comissão por cargo efetivo.** Propostas e pedidos registram `cargo_id`. Apenas cargos com `has_commission=1` entram na apuração, e Administradores são sempre isentos.
- **Sincronização RD Station CRM por CSV** (relatório semanal ou mensal, em vez de CSV/XLSX). Exporte o relatório de negócios do RD Station CRM como CSV.
- **Cadência manual preservada.** Os modelos de e-mail e o botão "Copiar texto" continuam disponíveis, separados do novo motor automático por WhatsApp.
- Todo o **motor de cálculo de comissão** (as 7 políticas: Vendedor IR, Vendedor Externo, Suporte IR, Interno Sênior, Representante, Consultor Sênior, Sem política) foi portado linha a linha para PHP (`includes/calc.php`) — os números devem bater exatamente com a versão original para os mesmos dados.

## Passo a passo de instalação no cPanel

1. **Banco de dados MySQL**
   - cPanel → *Bancos de Dados MySQL* → crie um banco (ex.: `dnbauto_crm`) e um usuário com senha forte, e associe o usuário ao banco com **todos os privilégios**.
   - Anote o host (geralmente `localhost`), nome do banco, usuário e senha.

2. **Importar o schema**
   - cPanel → *phpMyAdmin* → selecione o banco criado → aba **Importar** → escolha o arquivo `sql/schema.sql` deste projeto → Executar.
   - Isso cria as tabelas legadas e já popula a equipe (vendedores/políticas) e as linhas de produto.
   - Em seguida, ainda no phpMyAdmin, importe `sql/migrations/20260809_crm_ia_rbac.sql` para adicionar RBAC e IA.
   - Depois, importe `sql/migrations/20260809_whatsapp_rbac.sql` para adicionar WhatsApp, fila, buffer e Human Lock da v3.1.
   - Depois, importe `sql/migrations/20260809_followup_engine.sql` para adicionar catálogo de etapas, regras, auditoria e idempotência do motor v3.2.
   - Por último, importe `sql/migrations/20260810_crm_ia_rbac_cargos.sql` para adicionar pessoas vinculadas, múltiplos cargos e comissão por cargo da v3.3.

   **Instalação existente na v3.2:** não importe o schema completo nem repita migrações anteriores. Faça backup e importe somente `sql/migrations/20260810_crm_ia_rbac_cargos.sql` antes de publicar os arquivos da v3.3.

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
   - Na VPS, disponibilize também `POST /api/v1/ai/generate-followup`. O endpoint recebe o lead, a regra, a tentativa e até 20 mensagens de histórico, e deve retornar `message`, `guardrail` e, opcionalmente, `escalated`.

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
   - Acesse `https://seudominio.com.br/setup.php` — essa página só funciona enquanto não existir um usuário ativo. Crie o administrador (nome, e-mail, senha); o sistema vinculará a pessoa ao cargo `ADMINISTRADOR`.
   - Depois disso, `/setup.php` se desativa sozinho e redireciona para o login.

9. **Entrar**
   - Acesse `https://seudominio.com.br/login.php` (ou `/index.php`, que redireciona) e entre com o e-mail/senha criados.

10. **Revisar regras e criar o Cron Job de follow-up**
   - Entre como Administrador e abra **Cadência**. Todas as regras da v3.2 começam **desativadas** para evitar mensagens inesperadas logo após a migração.
   - Revise horas de inatividade, score mínimo e máximo de tentativas; ative somente as etapas desejadas depois de validar a VPS e o provedor de WhatsApp.
   - Em cPanel → **Cron Jobs**, agende a cada 15 minutos:

     ```bash
     /usr/local/bin/php -q /home/USUARIO/public_html/api/internal/followup/run.php 20 >/dev/null 2>&1
     ```

   - Troque `USUARIO`, `public_html` e o caminho do PHP pelos valores reais da hospedagem. O argumento `20` limita quantos leads serão selecionados por execução.
   - O script é exclusivamente CLI: chamadas pelo navegador recebem `404` e não executam o motor.

11. **(Opcional) Testar com dados de exemplo**
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
cadencia.php                → regras automáticas, operação e templates manuais
pedidos.php                  → pedidos de venda e faturamento
visitas.php                   → visitas/prospecções + importação JSON
comissoes.php                  → relatório mensal (imprimir/PDF pelo navegador)
configuracoes.php                → políticas por pessoa, apuração, simulação, usuários
usuarios.php                     → pessoas, credenciais, múltiplos cargos e supervisão
ia.php                           → roteador central e histórico de IA
casos.php                        → triagem de transbordos para atendimento humano
lead.php                         → score, responsável e linha do tempo da oportunidade
chat.php                         → Live Chat do WhatsApp com RBAC e Human Lock
api/v1/ai/process.php            → endpoint interno autenticado para a VPS
api/webhooks/whatsapp/index.php  → recepção autenticada de mensagens do provedor
api/internal/whatsapp/process_queue.php → processador para Cron Jobs do cPanel
api/internal/followup/run.php → motor automático para Cron Job de 15 minutos
api/v1/whatsapp/                 → conversas, mensagens, atribuição e envio autorizados
includes/whatsapp_service.php    → idempotência, buffer, fila e integração com a IA
includes/whatsapp_provider.php   → adaptador isolado do provedor de WhatsApp
includes/followup_service.php    → regras, seleção, IA, idempotência, envio e interrupção
includes/cargo_service.php       → cargos, grupos de acesso e elegibilidade de comissão
includes/                        → conexão PDO, autenticação, funções auxiliares, motor de comissão, seed
sql/schema.sql                      → schema legado + dados iniciais da equipe
sql/migrations/20260809_crm_ia_rbac.sql → atualização segura de instalações existentes
sql/migrations/20260809_whatsapp_rbac.sql → tabelas do WhatsApp da v3.1
sql/migrations/20260809_followup_engine.sql → catálogo, regras e logs da v3.2
sql/migrations/20260810_crm_ia_rbac_cargos.sql → pessoas, múltiplos cargos e comissão da v3.3
assets/style.css                      → ajustes finos (o layout usa Tailwind via CDN)
```

## Cargos e escopo de acesso

| Grupo derivado | Cargos | Escopo |
|---|---|---|
| `ADMINISTRADOR` | `ADMINISTRADOR` | Todo o CRM, usuários, IA, instâncias, casos e configurações; nunca gera comissão. |
| `SUPERVISOR` | `SUPERVISOR` | Leads, propostas, pedidos, visitas e indicadores da equipe; pode reatribuir leads. |
| `VENDEDOR` | Representante, Vendedor Interno Sênior, Vendedor Externo, Vendedor JR e Consultor Comercial Sênior | Somente registros atribuídos ao usuário; pode evoluir funil, registrar interações e emitir propostas. |
| `SUPORTE` | Suporte em Vendas JR | Casos transbordados pela IA, triagem e leads relacionados aos casos. |

Uma pessoa pode acumular cargos e, portanto, combinar os grupos autorizados. `usuarios.role` e `usuarios.vendedor_id` permanecem somente como compatibilidade legada; o RBAC usa `user_cargos` e o vínculo de pessoa usa `usuarios.pessoa_id`.

No Live Chat do WhatsApp, o `ADMINISTRADOR` vê todas as conversas; o `SUPERVISOR`, as conversas dos leads atribuídos à própria equipe; o `VENDEDOR`, apenas as dos próprios leads; e o `SUPORTE`, somente conversas ligadas a casos abertos ou em atendimento.

## Validação

Cada Pull Request executa validação de sintaxe no PHP 8.1 e testes de RBAC, IA, webhook, buffer, Human Lock e motor de follow-up. Para executar manualmente em um ambiente com PHP:

```bash
php tests/rbac_test.php
php tests/ai_payload_test.php
php tests/whatsapp_rbac_test.php
php tests/whatsapp_buffer_test.php
php tests/whatsapp_human_lock_test.php
php tests/whatsapp_webhook_test.php
php tests/followup_rules_test.php
php tests/followup_interruption_test.php
php tests/followup_payload_test.php
php tests/followup_idempotency_test.php
php tests/cargos_test.php
php tests/rbac_multicargo_test.php
php tests/comissao_cargo_test.php
php tests/pessoas_sync_test.php
```

## Atualização de produção no cPanel

O workflow `.github/workflows/deploy.yml` envia a `main` por FTPS ao cPanel. Arquivos `.sql`, `.md`, testes e `config.local.php` são excluídos do deploy, portanto a migração do banco é **manual pelo phpMyAdmin** e os segredos locais permanecem no servidor.

Ordem obrigatória para atualizar uma instalação existente:

1. Fazer backup do banco e dos arquivos atuais no cPanel.
2. Confirmar que `sql/migrations/20260809_crm_ia_rbac.sql` já foi instalado.
3. Confirmar que `sql/migrations/20260809_whatsapp_rbac.sql` já foi instalado.
4. Confirmar que `sql/migrations/20260809_followup_engine.sql` já foi instalado.
5. Baixar `sql/migrations/20260810_crm_ia_rbac_cargos.sql` e executá-lo no phpMyAdmin.
6. Entrar no phpMyAdmin e confirmar as tabelas `cargos`, `user_cargos` e as colunas `usuarios.pessoa_id`, `orcamentos.cargo_id` e `pedidos.cargo_id`.
7. Confirmar `whatsapp.local.php`, os endpoints da VPS e os Cron Jobs existentes; `config.php` e `sql/schema.sql` não precisam de alteração.
8. Publicar/mesclar a versão v3.3 para disparar o deploy FTPS.
9. Entrar em **Usuários**, revisar pessoas importadas, múltiplos cargos, supervisores e credenciais inativas antes de ativá-las.
10. Criar uma proposta controlada e confirmar que o cargo escolhido aparece no registro e somente cargos elegíveis alimentam **Comissões**.

## Segurança

- Senhas de usuário ficam com hash `bcrypt` (`password_hash`/`password_verify` do PHP).
- As pastas `includes/` e `sql/` têm `.htaccess` bloqueando acesso direto pelo navegador.
- `config.php` e `config.local.php` têm acesso HTTP bloqueado pelo `.htaccess`; o PHP continua podendo carregá-los internamente.
- Depois de criar o administrador, considere apagar ou renomear `setup.php` (embora ele já se autodesative sozinho).
- Todo o site deve rodar em **HTTPS** — ative o certificado SSL grátis do cPanel (AutoSSL/Let's Encrypt) para o domínio.
- Webhook e processador HTTP recusam chamadas sem token; mensagens repetidas são eliminadas pelo ID do provedor.
- O motor de follow-up só roda por CLI, reserva cada tentativa com chave única e não repete automaticamente um envio cujo resultado ficou incerto.
- `whatsapp.local.php` nunca deve ser enviado ao GitHub nem compartilhado no navegador.

## Próximos passos possíveis (fora do escopo desta entrega)

- Enviar os e-mails de cadência automaticamente via **Resend** (API de envio transacional) em vez de só copiar o texto — dá para acrescentar um botão "Enviar" em `cadencia.php` chamando a API do Resend com uma chave configurada em `config.php`, quando vocês tiverem decidido esse fluxo.
- Acrescentar extração de PDF na VPS, mantendo as credenciais fora do navegador e usando as importações JSON existentes como contrato de entrada.
