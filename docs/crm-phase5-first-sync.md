# CRM DNB — Fase 5: primeira sincronização RD Station

A Fase 5 executa a primeira carga real de dados do RD Station para o CRM DNB, em lotes pequenos e auditáveis.

## Pré-requisitos

Antes do botão **Iniciar primeira sincronização** ficar operacional:

1. migrações das Fases 1, 2, 4 e 5 precisam estar instaladas;
2. RD Station Marketing e RD Station CRM precisam estar conectados por OAuth;
3. a descoberta da Fase 4 deve ter sido executada;
4. no Marketing, `name` e `email` precisam estar selecionados e mapeados para `contact.name` e `contact.email`;
5. no CRM, o campo personalizado de CNPJ da Empresa precisa estar mapeado para `company.cnpj`;
6. ao menos um funil/etapa do CRM precisa estar habilitado.

O requisito do CNPJ impede que a primeira carga de Empresas tente deduplicar apenas por nome.

## Migração

Aplicar:

`sql/migrations/20260918_rd_phase5_first_sync.sql`

Ela cria somente tabelas operacionais da integração:

- `rd_sync_jobs`;
- `rd_sync_logs`;
- `rd_sync_conflicts`.

Não adiciona `rd_id` às entidades comerciais.

## Sequência do job

O processo é incremental:

1. `marketing_contacts`: lista todos os contatos da segmentação padrão **Todos os contatos da base de Leads**;
2. `marketing_contact_details`: consulta individualmente os contatos em lotes de 10 para obter os campos detalhados e personalizados;
3. `crm_organizations`: importa todas as Empresas do RD CRM;
4. `crm_contacts`: importa todos os Contatos do RD CRM e associa cada um à Empresa correspondente;
5. `crm_deals`: importa Negociações criadas nos últimos 6 meses;
6. `finish`: encerra o job e mantém os conflitos pendentes para revisão.

A segunda passagem do Marketing é necessária porque a própria documentação do RD orienta usar a segmentação para enumerar a base e a consulta individual para obter os dados completos do contato.

## Empresas

A identidade interna continua sendo `dnb_id`.

O ID da Empresa no RD fica em `crm_external_links`.

Para um registro novo:

- CNPJ único já existente → vincula o RD à Empresa DNB existente;
- mais de uma Empresa com o mesmo CNPJ → conflito `duplicate_cnpj`;
- CNPJ ainda inexistente → cria nova Empresa DNB;
- Empresa sem CNPJ → o núcleo CRM a marca para conferência.

Uma atualização que tentaria mudar o CNPJ para um CNPJ já utilizado por outra Empresa gera `cnpj_change_collision`.

## Contatos

O e-mail é a chave comercial principal.

- um contato local com o mesmo e-mail → cria apenas o vínculo externo;
- vários contatos com o mesmo e-mail → `duplicate_email`;
- sem correspondência → cria novo Contato;
- sem e-mail → cria o registro para não perder o dado, mas marca `needs_review`.

Mudança de e-mail que colidiria com outro Contato gera `email_change_collision`.

O mesmo Contato DNB pode possuir um vínculo `rd_marketing` e outro `rd_crm`, sem duplicar o registro local.

## Marketing

O job localiza a segmentação padrão da conta e pagina os contatos.

Depois, percorre os vínculos `rd_marketing` e consulta cada contato pelo e-mail ou UUID para aplicar somente os campos selecionados e mapeados na Fase 4.

O campo Empresa preenchido no RD Marketing não é fornecido pelas APIs de Contatos/Segmentações. Portanto, a Empresa é consolidada prioritariamente pelo RD CRM. Se houver um campo de CNPJ explicitamente mapeado no Marketing, o sistema também pode associar/criar a Empresa por esse CNPJ.

## RD CRM

A carga usa a API v2:

- `/crm/v2/organizations`;
- `/crm/v2/contacts`;
- `/crm/v2/deals`.

A paginação usa `page[number]`, `page[size]` e `links.next`.

Negociações usam RDQL com `created_at >=` a data de seis meses antes do início do job.

## Relacionamentos

Empresas são carregadas antes dos Contatos do CRM.

Assim, o `organization_id` remoto pode ser convertido em `company_id` local.

Depois, as Negociações conseguem relacionar:

- Empresa;
- Contatos;
- responsável;
- funil;
- etapa;
- motivo de perda;
- campos personalizados mapeados.

## Ganhos históricos

Uma Negociação importada com status `won`:

- fica como Ganha;
- mantém `origin=rd_crm`;
- recebe `order_status=none`;
- não cria Pedido;
- não cria Orçamento artificial.

A tela da Negociação informa que é um ganho histórico do RD Station e que nenhum Pedido local foi criado pela importação.

## Conflitos e fonte oficial

Os mapeamentos da Fase 4 continuam valendo:

- `rd`: RD atualiza o campo;
- `dnb`: um valor local existente é preservado;
- `manual_review`: valores divergentes geram conflito.

CNPJ/e-mail ambíguos também viram conflito. Nada é mesclado silenciosamente.

## Painel CMD

Configurações → RD Station exibe:

- etapa;
- página;
- janela dos últimos seis meses;
- contadores de criação/atualização;
- conflitos;
- logs em tempo real;
- histórico dos jobs;
- botão cancelar;
- botão retomar quando uma execução falha.

O navegador aciona um passo por vez. Se a aba for fechada, o job permanece no banco e pode continuar quando a tela for aberta novamente.

O mesmo job usa `GET_LOCK` do MySQL para impedir duas execuções simultâneas.

## Limite da API

Se o RD retornar HTTP 429, o job não falha. Ele grava um `retry_after`, espera um intervalo de segurança e tenta novamente.

## Próxima fase

Depois da primeira carga e revisão dos conflitos, a próxima fase pode ativar sincronização incremental recorrente e webhooks, sem repetir a importação completa.
