# [CONTEXTO E BRANCHES]

- Repositório: deulaag/crmdnb
- Fluxo: Criar branch `feature/integrations-rdstation-editable-tokens` a partir da `main` e abrir PR.

# [ALTERAÇÕES EXIGIDAS]

Tornar totalmente editáveis os campos de formulário para Tokens e APIs na aba de Integrações (WhatsApp e VPS IA) e implementar a seção de configuração da API do RD Station com suporte a OAuth 2.0 e Callback no domínio `https://crm.dnbair.com.br/`.

---

### 1. Habilitação de Campos Editáveis de Tokens e APIs

Substituir visualizações estáticas ou bloqueadas por campos de formulário totalmente editáveis (`<input type="text">`, `<input type="password">` e `<textarea>`), com rotas no backend para gravação e atualização segura:

#### A. Conexão WhatsApp (Evolution API / Z-API / Webhook)
- `URL da API/Instância`: Campo de texto livre e editável.
- `API Key / Secret Token`: Campo de texto editável com alternância de visibilidade (mostrar/ocultar senha).
- `Nome da Instância`: Campo de texto editável.
- Botão "Salvar Configurações de WhatsApp".

#### B. Conexão IA & RAG (VPS Externa)
- `URL do Endpoint VPS`: Campo de texto editável (ex: `https://vps-ia.seu-dominio.com/api/v1`).
- `Bearer Token / API Key VPS`: Campo editável para chave secreta de comunicação.
- `Modelo Padrão de LLM`: Campo de seleção/texto editável (ex: `gpt-4o`, `deepseek-r1`, `claude-3-5-sonnet`).
- Botão "Salvar Configurações de IA".

---

### 2. Nova Seção: Integração RD Station API

Adicionar na mesma aba de Integrações a sub-seção **RD Station CRM / Marketing**:

- **Campos de Formulário Editáveis**:
  - `Client ID`: Campo editável para o ID da aplicação criada no portal do desenvolvedor RD Station.
  - `Client Secret`: Campo editável para o segredo da aplicação.
  - `URL de Callback (OAuth Redirect)`: Campo pré-preenchido e editável configurado para o domínio do projeto:
    - `https://crm.dnbair.com.br/integrations/rdstation/callback`
- **Fluxo de Autenticação & Status**:
  - Botão "Conectar com RD Station" (inicia fluxo OAuth 2.0).
  - Indicador de status ("Conectado" / "Não Conectado").
  - Armazenamento automático e renovação de `access_token` e `refresh_token` no banco MySQL.

---

### 3. Persistência de Dados e Segurança no cPanel/MySQL
- Criar/Atualizar tabela `system_integrations`:
  `id`, `service_name` (whatsapp, ai_vps, rdstation), `config_key`, `config_value` (criptografado para segredos/tokens), `updated_at`.
- Endpoints de gravação protegidos com middleware de autenticação (permissão restrita ao perfil **ADMINISTRADOR**).

# [PROTOCOLO DE PLANEJAMENTO DO AGENTE]

1. Leia as alterações exigidas acima.
2. Proponha um plano textual listando:
   - Quais arquivos serão modificados.
   - O título do commit e do PR.
3. PAUSE e aguarde a aprovação do usuário para o plano.

# [EXECUÇÃO DA ETAPA 1: CRIAÇÃO]

*Após o plano ser aprovado por mim no chat:*

1. Aplique as alterações no código.
2. Faça o commit e o push.
3. Abra o Pull Request.
4. Forneça o link do PR e encerre sua execução imediatamente dizendo: "PR Criado. Aguardando revisão manual."