# [CONTEXTO E BRANCHES]

- Repositório: deulaag/crmdnb
- Fluxo: Criar branch `feature/crm-ia-rbac-cargos` a partir da `main` e abrir PR.

# [ALTERAÇÕES EXIGIDAS]

Implementar a estrutura de Gestão de Usuários e Pessoas com suporte a Múltiplos Cargos, Regras de Comissão por Cargo, controle hierárquico (RBAC), além do módulo central de IA (Roteador, Casos de Atendimento, Guardrails e Lead Scoring).

### 1. Estrutura de Cargos e Regras de Comissão
- Criar tabela `cargos`: `id`, `title`, `has_commission` (boolean), `created_at`.
- Seeds/Cargos Padrão do Sistema:
  1. `ADMINISTRADOR` (`has_commission = false`)
  2. `SUPERVISOR` (`has_commission = true`)
  3. `Representante Comercial Autônomo` (`has_commission = true`)
  4. `Vendedor Interno Sênior` (`has_commission = true`)
  5. `Vendedor Externo` (`has_commission = true`)
  6. `Vendedor JR` (`has_commission = true`)
  7. `Consultor Comercial Sênior` (`has_commission = true`)
  8. `Suporte em Vendas JR` (`has_commission = true`)

### 2. Suporte a Múltiplos Cargos por Usuário/Pessoa
- Criar tabela pivô `user_cargos`: `user_id`, `cargo_id`.
- Permitir que uma mesma pessoa/usuário possua múltiplos cargos atribuídos simultaneamente.
- Regra de Comissão: Apenas propostas/vendas geradas sob cargos marcados com `has_commission = true` alimentam o módulo de cálculo de comissão. O perfil de `ADMINISTRADOR` é isento de regras de comissionamento.

### 3. Sincronização e Gestão de Pessoas Existentes
- Mapear e importar todas as pessoas/colaboradores já existentes no banco de dados vinculando-os à tabela `users` via `pessoa_id`.
- Interface administrativa no painel (ADMINISTRADOR) para:
  - Listar, editar e alterar dados de pessoas e seus respectivos usuários.
  - Selecionar múltiplos cargos para o mesmo colaborador.
  - Definir e reatribuir o `SUPERVISOR` responsável de cada vendedor/consultor.
  - Ativar/desativar credenciais de acesso ao sistema.

### 4. Matriz de Permissões no CRM (RBAC)
- **ADMINISTRADOR**: Acesso total a todas as funções, servidor, IA, instâncias e gestão de usuários.
- **SUPERVISOR**: Gestão da equipe comercial vinculada, visualização de propostas, relatórios do time e redistribuição de leads.
- **VENDEDOR** (Vendedores Internos/Externos, Consultores e Representantes Autônomos): Acesso restrito aos leads atribuídos a ele (`assigned_to_user_id = current_user_id`), alteração de etapas do funil, registro de interações e emissão de propostas.
- **SUPORTE** (Suporte em Vendas JR): Acesso à fila de Casos de Atendimento e auxílio nas demandas técnicas/comerciais do transbordo.

### 5. Arquitetura de Comunicação (cPanel <-> VPS de IA)
- Requisição assíncrona do cPanel para a VPS no endpoint `POST /api/v1/ai/route-and-process`.
- A VPS executa RAG, Roteamento Multi-Agente e Guardrails, retornando a resposta e pontuação do lead.

### 6. Módulo de Casos de Atendimento (Support Cases)
- Criar tabela `support_cases`: `id`, `lead_id`, `agent_id`, `reason_blocked`, `customer_query`, `status`, `created_at`.
- Notificação automática para os perfis **SUPORTE**, **SUPERVISOR** e **ADMINISTRADOR** quando a IA transbordar um atendimento.

### 7. Lead Scoring & Linha do Tempo
- Tabela `lead_scores` (`lead_id`, `score`, `justification`, `updated_at`).
- Tabela `lead_timeline` (`lead_id`, `event_type`, `description`, `actor`, `created_at`).
- Exibição de pontuação e histórico na ficha do lead.

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