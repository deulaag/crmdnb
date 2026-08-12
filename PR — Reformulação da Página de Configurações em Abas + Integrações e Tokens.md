# [CONTEXTO E BRANCHES]

- Repositório: deulaag/crmdnb
- Fluxo: Criar branch `feature/settings-tabs-integrations` a partir da `main` e abrir PR.

# [ALTERAÇÕES EXIGIDAS]

Refatorar a página monolítica de Configurações para uma interface moderna dividida por Abas (Tabs UI), organizando os formulários extensos sem alterar a ordem das variáveis, nomes de campos, cálculos ou regras de negócio existentes no backend. Adicionar uma aba dedicada para Tokens, APIs e Integrações do ecossistema (WhatsApp, VPS de IA e Serviços).

---

### 1. Estrutura de Navegação por Abas (Frontend UI)

Organizar a página em 5 abas principais preservando 100% dos IDs, names de formulários e rotas de salvamento originais:

#### Aba 1: `Simulação & Parâmetros Fiscais`
- Módulo de "Demonstração com Dados de Demonstração" (Botões: Carregar Simulação, Excluir Parâmetros Fiscais).
- Configurações gerais de simulação e parâmetros de teste.

#### Aba 2: `Aprovação & Regras Gerais`
- Tabela de "Aprovação de Pessoas / Regras (Valores Máximos Recomendados)".
- Mapeamento percentual recomendado por colaborador (`Carlos Alencar`, `Dirceu Carvalho`, `Felipe Yuzo Ito`, etc.).

#### Aba 3: `Metas e Comissões por Colaborador`
- Organização sanfonada/cards individuais por pessoa mantendo a ordem exata da base:
  - Carlos Alencar (Representante Comercial Autônomo)
  - Dirceu Carvalho (Vendedor Interno)
  - Felipe Yuzo Ito (Suporte em Vendas)
  - Thiago Gabrili (Consultor Comercial Sênior)
  - Rafael Verardino (Vendedor Externo)
  - Rafaela Patrício (Suporte em Vendas)
  - Yasmin (Suporte em Vendas)
  - Rogério Viana (Vendedor)
- Campos preservados por card: Meta mensal/anual (R$), % comissão, % margem, bonificação, margem mínima, prazos e comissão máxima por venda.
- Mantém inalterado o cálculo da base e o botão individual de salvamento ("Salvar alterações").

#### Aba 4: `Integrações, Tokens e APIs` (NOVA ABA)
- **Conexão WhatsApp (Evolution API / Z-API / WhatsApp Webhook)**:
  - URL da API / Instância do WhatsApp.
  - Token de Autenticação / API Key.
  - Exibição de Status de Conexão e botão para gerar QR Code de sincronização.
- **Conexão IA & RAG (VPS Externa)**:
  - Endpoint da VPS de IA (ex: `https://vps-ia.seu-dominio.com/api/v1`).
  - Bearer Token / Secret Key de comunicação com a VPS.
  - Modelo Padrão de LLM e temperatura (OpenAI/DeepSeek/Anthropic).
- **Tokens de Serviços e Webhooks**:
  - Chaves de integração externa, Webhooks de entrada/saída e Secrets de autenticação.

#### Aba 5: `Acesso, Licença e Suporte`
- Informações do Módulo "Acesso e Suporte".
- Status da licença e botão de "Renovar módulo".

---

### 2. Regras de Preservação e Compatibilidade Backend
- **Zero alteração em cálculos**: As rotas de salvamento e os controllers que processam os valores de margem, meta e comissão permanecem intactos.
- **Manutenção dos `names` dos inputs**: Todos os inputs do formulário mantêm seus nomes originais no HTML para evitar quebras nos endpoints POST existentes.
- **Persistência de Aba Ativa**: Salvar a aba selecionada no `localStorage` do navegador para que o usuário permaneça na mesma aba após recarregar ou salvar alterações.

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