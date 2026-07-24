# DNB · CRM Comercial — versão PHP + MySQL (cPanel)

Porte completo do CRM (React/artefato Claude) para PHP puro + MySQL, pronto para hospedagem compartilhada (cPanel, só File Manager/FTP — sem Composer/SSH).

## O que mudou em relação à versão original

- **Login obrigatório.** O app original não tinha autenticação (dados "compartilhados" abertos). Nesta versão é preciso logar — veja `/setup.php` abaixo.
- **Sem extração de PDF por IA.** Os botões que liam PDF de orçamento (ERP) e de OS de visita (iliot) direto com a API da Anthropic foram removidos — isso exigiria expor uma chave de API no seu servidor. Use a importação por **JSON** (Orçamentos e Visitas já têm essa opção) como ponte com o sistema externo que vocês forem usar para extrair os PDFs.
- **Sincronização Salesforce por CSV** (em vez de CSV/XLSX). Exporte o relatório do Salesforce como CSV.
- **Cadência sem IA.** Os e-mails de follow-up são gerados por **modelo/template** (sem chamar a Anthropic), com botão "Copiar texto" — sem a integração de rascunho automático no Outlook (isso era exclusivo do Claude.ai).
- Todo o **motor de cálculo de comissão** (as 7 políticas: Vendedor IR, Vendedor Externo, Suporte IR, Interno Sênior, Representante, Consultor Sênior, Sem política) foi portado linha a linha para PHP (`includes/calc.php`) — os números devem bater exatamente com a versão original para os mesmos dados.

## Passo a passo de instalação no cPanel

1. **Banco de dados MySQL**
   - cPanel → *Bancos de Dados MySQL* → crie um banco (ex.: `dnbauto_crm`) e um usuário com senha forte, e associe o usuário ao banco com **todos os privilégios**.
   - Anote o host (geralmente `localhost`), nome do banco, usuário e senha.

2. **Importar o schema**
   - cPanel → *phpMyAdmin* → selecione o banco criado → aba **Importar** → escolha o arquivo `sql/schema.sql` deste projeto → Executar.
   - Isso cria as tabelas e já popula a equipe (vendedores/políticas) e as linhas de produto.

3. **Enviar os arquivos**
   - Envie **todo o conteúdo** desta pasta (exceto `sql/`, que fica só como referência — mas não tem problema subir também, está protegida por `.htaccess`) para a pasta pública do seu domínio/subdomínio no cPanel (geralmente `public_html` ou `public_html/crm`, dependendo de onde você quer o sistema).
   - Pode usar o *File Manager* do cPanel (upload do .zip e "Extrair") ou FTP.

4. **Configurar a conexão**
   - Edite `config.php` no servidor (via File Manager → Editar) e preencha `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` com os dados do passo 1.

5. **Criar o primeiro usuário**
   - Acesse `https://seudominio.com.br/setup.php` — essa página só funciona uma vez (enquanto não existir nenhum usuário). Crie o administrador (nome, e-mail, senha).
   - Depois disso, `/setup.php` se desativa sozinho e redireciona para o login.

6. **Entrar**
   - Acesse `https://seudominio.com.br/login.php` (ou `/index.php`, que redireciona) e entre com o e-mail/senha criados.

7. **(Opcional) Testar com dados de exemplo**
   - Aba **Configurações** → "Carregar simulação" — popula o mês de julho/2026 com o mesmo cenário de demonstração do app original. "Limpar tudo" zera a base e restaura a equipe padrão.

## Requisitos do servidor

- PHP **8.1 ou superior** com extensões `pdo_mysql` e `json` (padrão em praticamente toda hospedagem cPanel atual).
- MySQL 5.7+ / 8.0 ou MariaDB 10.2+ (suporte a coluna `JSON`).
- Não usa Composer nem bibliotecas externas — é PHP puro, funciona só com upload de arquivos.

## Estrutura de pastas

```
config.php              → credenciais do banco (edite após o upload)
setup.php                → cria o primeiro usuário administrador
login.php / logout.php
painel.php                → dashboard (farol de metas, KPIs automáticos)
orcamentos.php             → funil de vendas, nova oportunidade, importações, conversão em pedido
cadencia.php                → follow-up por template + histórico de toques
pedidos.php                  → pedidos de venda e faturamento
visitas.php                   → visitas/prospecções + importação JSON
comissoes.php                  → relatório mensal (imprimir/PDF pelo navegador)
configuracoes.php                → políticas por pessoa, apuração, simulação, usuários
includes/                          → conexão PDO, autenticação, funções auxiliares, motor de comissão, seed
sql/schema.sql                      → schema + dados iniciais da equipe
assets/style.css                      → ajustes finos (o layout usa Tailwind via CDN)
```

## Segurança

- Senhas de usuário ficam com hash `bcrypt` (`password_hash`/`password_verify` do PHP).
- As pastas `includes/` e `sql/` têm `.htaccess` bloqueando acesso direto pelo navegador.
- Depois de criar o administrador, considere apagar ou renomear `setup.php` (embora ele já se autodesative sozinho).
- Todo o site deve rodar em **HTTPS** — ative o certificado SSL grátis do cPanel (AutoSSL/Let's Encrypt) para o domínio.

## Próximos passos possíveis (fora do escopo desta entrega)

- Enviar os e-mails de cadência automaticamente via **Resend** (API de envio transacional) em vez de só copiar o texto — dá para acrescentar um botão "Enviar" em `cadencia.php` chamando a API do Resend com uma chave configurada em `config.php`, quando vocês tiverem decidido esse fluxo.
- Reintroduzir a extração de PDF por IA usando o sistema externo que vocês mencionaram, alimentando a importação por JSON já existente em Orçamentos/Visitas.
