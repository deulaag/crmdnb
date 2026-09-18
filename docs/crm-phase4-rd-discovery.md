# CRM DNB — Fase 4: descoberta e mapeamento RD Station

A Fase 4 usa as conexões OAuth da Fase 3 para ler a estrutura real das contas RD Station Marketing e RD Station CRM. Ela **não importa ainda registros comerciais**.

## Migração

Aplicar no MySQL, depois das migrações das Fases 1 e 2:

`sql/migrations/20260918_rd_phase4_discovery_mapping.sql`

A migração cria somente tabelas de integração:

- `rd_discovery_runs`: histórico das leituras estruturais;
- `rd_discovery_items`: cache dos metadados encontrados;
- `rd_field_mappings`: campo RD → campo CRM DNB e fonte oficial por campo;
- `rd_user_mappings`: usuário RD CRM → usuário interno;
- `rd_pipeline_mappings`: funil/etapa RD → nomes usados pelo CRM DNB.

Os identificadores externos continuam confinados nessas tabelas. Empresas, Contatos e Negociações continuam usando `dnb_id`.

## RD Station Marketing

O botão **Ler estrutura do Marketing** consulta os campos da conta.

O painel mostra:

- campo padrão ou personalizado;
- `api_identifier`;
- tipo;
- seleção para futura sincronização;
- campo de destino no CRM DNB;
- regra de conflito: RD prevalece, DNB prevalece ou revisão manual.

Campos essenciais conhecidos recebem uma sugestão inicial de destino, mas o administrador decide o mapeamento final.

## RD Station CRM

O botão **Ler estrutura do CRM** consulta e pagina:

- usuários;
- funis;
- etapas de cada funil;
- campos personalizados;
- fontes;
- motivos de perda;
- segmentos;
- campanhas.

Uma falha em um recurso não descarta os demais. O run pode terminar como `partial`, e o painel CMD mostra o detalhe.

## Usuários

Usuários do RD são mantidos no inventário mesmo quando estão inativos, para preservar referências históricas.

Quando o e-mail do RD coincide exatamente com um usuário ativo do CRM DNB, o vínculo local é sugerido automaticamente. O ADM pode revisar ou remover esse vínculo.

## Funis e etapas

Todos os funis e etapas descobertos são habilitados por padrão.

A associação funil → etapa é preservada. Depois da descoberta, os formulários de criar e editar Negociação passam a usar esse catálogo. A tela não grava IDs RD na Negociação; grava somente os nomes locais definidos no mapeamento.

Negociações antigas com nomes fora do catálogo continuam editáveis como valores legados.

## Campos personalizados

Campos personalizados das entidades `contact`, `organization` e `deal` podem ser mapeados para:

- Contato;
- Empresa;
- Negociação.

Campos de produto permanecem inventariados, mas não são mapeados ao núcleo comercial nesta fase.

## Fonte oficial por campo

A propriedade é definida no próprio mapeamento:

- `rd`: em conflito, o RD Station é a fonte oficial;
- `dnb`: em conflito, o CRM DNB é a fonte oficial;
- `manual_review`: a futura sincronização deverá sinalizar o conflito para revisão.

Isso evita uma regra global que sobrescreva campos indiscriminadamente.

## Painel CMD

A aba RD Station exibe as últimas execuções de descoberta com:

- data/hora;
- produto;
- status;
- total de metadados;
- totais por recurso;
- erro resumido quando houver.

## Segurança e escopo

A descoberta usa somente chamadas autenticadas server-side já centralizadas em `rd_station_service.php`.

Paginação retornada pelo RD é aceita somente quando o próximo link continua em `https://api.rd.services`.

A Fase 4 não contém `INSERT` em Empresas, Contatos ou Negociações e não cria Pedido. A primeira sincronização de registros permanece para a próxima fase.
