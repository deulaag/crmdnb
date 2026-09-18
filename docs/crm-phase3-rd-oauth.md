# CRM DNB — Fase 3: RD Station OAuth

A Fase 3 conecta o CRM DNB aos dois produtos do ecossistema RD Station sem iniciar ainda a sincronização completa de dados.

## Objetivo

Preparar autenticação segura e independente para:

- RD Station Marketing;
- RD Station CRM v2.

As credenciais de um produto não são reutilizadas no outro.

## Configuração no CRM

A nova aba **Configurações → RD Station** permite ao administrador:

- definir a URL pública HTTPS do CRM;
- visualizar os callbacks exatos;
- salvar Client ID;
- salvar Client Secret sem exibi-lo novamente;
- iniciar ou refazer OAuth;
- testar a conexão;
- renovar o token manualmente;
- desconectar removendo tokens sem apagar as credenciais do aplicativo.

Os segredos e tokens ficam em `integrations.local.php`, que já é ignorado pelo Git e recebe permissão restrita no servidor.

## Aplicativos no App Publisher

Devem ser criados dois aplicativos distintos:

1. produto **RD Station Marketing**;
2. produto **RD Station CRM**.

Cada aplicativo deve receber o callback exibido pelo CRM.

Callbacks usados pelo sistema:

- `/api/oauth/rdstation/marketing/callback.php`
- `/api/oauth/rdstation/crm/callback.php`

A URL completa depende de `DNB_PUBLIC_BASE_URL`.

## OAuth RD Station Marketing

Authorization endpoint:

`https://api.rd.services/auth/dialog`

Token endpoint:

`https://api.rd.services/auth/token`

Troca inicial de code:

`https://api.rd.services/auth/token?token_by=code`

O teste de leitura usa:

`GET https://api.rd.services/platform/contacts/fields`

Esse endpoint também será útil na fase seguinte para descobrir os campos disponíveis na conta.

## OAuth RD Station CRM v2

Authorization endpoint:

`https://accounts.rdstation.com/oauth/authorize`

Token endpoint:

`https://api.rd.services/oauth2/token`

Base da API v2:

`https://api.rd.services/crm/v2/`

O teste de leitura usa:

`GET https://api.rd.services/crm/v2/users?page[size]=1`

A renovação usa `grant_type=refresh_token`. O refresh token retornado em cada renovação substitui o anterior.

## Segurança

O fluxo usa `state` aleatório por tentativa e armazena somente seu hash na sessão.

O callback:

- exige sessão administrativa;
- valida o mesmo usuário que iniciou a conexão;
- valida `state` com `hash_equals`;
- rejeita fluxo com mais de 15 minutos;
- não imprime access token ou refresh token;
- grava os tokens somente no arquivo local protegido.

O cliente HTTP só permite chamadas server-side para `api.rd.services`.

## Renovação

O serviço central renova o access token antes de expirar ou após uma resposta 401.

Há também um script somente CLI:

`api/internal/rdstation/refresh.php`

Ele pode ser agendado no Cron do cPanel para manter as conexões aquecidas até a sincronização recorrente da fase seguinte.

Exemplo conceitual:

`php /caminho/do/crm/api/internal/rdstation/refresh.php`

O caminho absoluto depende da hospedagem.

## O que não faz parte desta fase

A Fase 3 não:

- importa contatos;
- importa empresas;
- importa negócios;
- altera funis;
- mapeia campos automaticamente;
- cria webhooks RD;
- sincroniza dados em lote.

Essas ações serão implementadas depois que as duas conexões estiverem autenticadas e os metadados reais da conta puderem ser consultados.

## Próxima fase

A Fase 4 poderá usar as conexões já prontas para descobrir e apresentar no ADM:

### RD Station Marketing

- campos padrão;
- campos personalizados;
- identificadores de API;
- metadados necessários para Contatos e Pré-vendas.

### RD Station CRM

- usuários;
- funis;
- etapas por funil;
- campos personalizados;
- fontes;
- motivos de perda e outros metadados necessários ao mapeamento.

O administrador poderá então escolher o que será mapeado antes de iniciar sincronizações reais.
