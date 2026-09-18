# CRM DNB — Fase 9: saída controlada DNB → RD CRM

A Fase 9 implementa a direção que ainda faltava na integração: alterações feitas no CRM DNB podem ser enviadas ao **RD Station CRM**.

A automação nasce **desligada**. O ADM revisa primeiro a propriedade de cada campo em **Configurações → RD Station** e só então habilita o envio automático.

## Identidade

A regra continua a mesma das fases anteriores:

- Empresa usa `EMP-...`;
- Contato usa `CON-...`;
- Negociação usa `NEG-...`;
- nenhum `rd_id` é adicionado às tabelas comerciais;
- IDs externos continuam exclusivamente em `crm_external_links`.

## Entidades enviadas

A Fase 9 cobre somente:

- Empresa → Organization do RD CRM;
- Contato → Contact do RD CRM;
- Negociação → Deal do RD CRM.

Pedido e Orçamento **não** são enviados ao RD.

## Fonte oficial campo a campo

A migração cria `rd_outbound_field_rules`.

Cada campo pode ficar em um destes modos:

- **RD Station prevalece**: entrada RD → DNB pode atualizar o campo; o DNB não envia este campo de volta;
- **CRM DNB prevalece**: alterações locais entram na fila e o campo pode ser enviado ao RD;
- **Revisar conflito**: divergência exige revisão, sem sobrescrita automática.

A mesma regra é consultada também pelo sincronizador de entrada para os campos principais. Isso evita loop de “RD atualiza DNB → DNB devolve para RD” em campos cujo dono é o RD.

Campos personalizados continuam aproveitando o mapeamento da Fase 4. Apenas mapeamentos marcados com **CRM DNB prevalece** entram no payload de saída.

## Regras iniciais

Começam como propriedade DNB:

- Empresa: nome, website e endereço;
- Contato: nome, cargo, e-mail, telefone e Empresa;
- Negociação: título, valor, Empresa, responsável, funil/etapa e contato principal.

Começam como propriedade RD:

- status da Negociação;
- motivo de perda.

O ADM pode mudar estas escolhas antes de ativar a automação.

## Criação e atualização no RD CRM

A implementação usa os endpoints CRM v2:

- `POST /crm/v2/organizations` e `PUT /crm/v2/organizations/{id}`;
- `POST /crm/v2/contacts` e `PUT /crm/v2/contacts/{id}`;
- `POST /crm/v2/deals` e `PUT /crm/v2/deals/{id}`.

Como o RD só aceita uma Negociação nova com status `ongoing`, a criação sempre nasce como `ongoing`. Se o ADM definir o status como propriedade DNB e o negócio local já estiver ganho/perdido, o sistema primeiro cria, grava o ID externo com segurança e só então faz um `PUT` para o status final.

## Prevenção de duplicidade

Antes de criar uma Empresa sem vínculo:

1. se o CNPJ estiver mapeado para um campo personalizado do RD CRM, procura por esse CNPJ;
2. se houver exatamente uma correspondência, reutiliza o registro e cria o vínculo;
3. se houver mais de uma, bloqueia a saída;
4. como fallback, procura o nome exato da Empresa.

Antes de criar um Contato, procura o e-mail.

Nenhuma correspondência ambígua é mesclada silenciosamente.

Empresas e Contatos locais marcados como `needs_review` também são bloqueados até revisão.

## Dependências

A fila processa na ordem:

`Empresa → Contato → Negociação`

Quando um Contato depende de uma Empresa ainda sem vínculo, a Empresa é enfileirada primeiro e o Contato aguarda sem consumir suas tentativas.

Uma Negociação faz o mesmo para sua Empresa e seus Contatos.

Responsável e etapa precisam ter mapeamento válido da Fase 4; se não tiverem, o item fica **Bloqueado** para correção administrativa.

## Fila

A tabela `rd_outbound_queue` registra:

- entidade e `dnb_id`;
- motivo da alteração;
- status;
- tentativas;
- usuário solicitante;
- ID externo após sucesso;
- HTTP;
- erro/bloqueio;
- timestamps.

Estados:

- `pending`;
- `processing`;
- `success`;
- `error`;
- `blocked`.

Itens bloqueados/erro podem ser reabertos pelo ADM depois que o mapeamento for corrigido.

## Operação automática

Quando **Ativar envio automático DNB → RD** estiver marcado:

- criar/editar Empresa enfileira a Empresa;
- criar/editar Contato enfileira o Contato;
- criar/editar Negociação enfileira a Negociação;
- vínculo de Contato também atualiza a fila;
- o mesmo cron da Fase 6 processa até cinco saídas por execução antes da reconciliação inbound.

A fila é idempotente por entidade/DNB enquanto houver item pendente/processando: várias alterações rápidas consolidam o mesmo item em vez de criar uma sequência de duplicatas.

## Operação manual

O ADM possui:

- **Processar saída agora** em Configurações;
- **Colocar na fila RD** nas telas de Empresa, Contato e Negociação;
- **Reabrir** em itens bloqueados/erro.

O envio manual funciona mesmo com a automação desligada.

## Rate limit

HTTP 429 devolve o item para `pending` e agenda nova tentativa; não cria um novo registro remoto.

## Migração

Aplicar:

`sql/migrations/20260918_rd_phase9_outbound_sync.sql`

Depois:

1. abrir **Configurações → RD Station**;
2. revisar “Quem prevalece” em cada campo;
3. manter a automação desligada durante o primeiro teste;
4. usar uma Empresa/Contato/Negociação conhecida e **Colocar na fila RD**;
5. clicar **Processar saída agora** e conferir no RD;
6. somente depois habilitar o envio automático.

## Limites intencionais

A Fase 9 não envia:

- Pedidos;
- Orçamentos;
- Visitas;
- dados do RD Marketing.

Ela fecha apenas a bidirecionalidade do núcleo comercial entre CRM DNB e RD CRM.
