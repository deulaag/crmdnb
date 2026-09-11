# Leitura inteligente da Ordem de Serviço · CRM → n8n → Extrator/Azure/IA

## Porta pública do CRM

O navegador e os usuários do CRM usam somente:

`POST https://crm.dnbair.com.br/os/analisar`

Esse endpoint pertence ao CRM no cPanel. Ele valida autenticação, papel e CSRF, lê o PDF localmente como fallback, e encaminha o arquivo para o webhook do n8n configurado pelo ADM.

O endereço real do n8n/VPS nunca precisa aparecer na interface de Visitas.

## Configuração no painel ADM

Em **Configurações → Integrações e Tokens → Leitura inteligente da OS**:

- Ativar/desativar leitor inteligente.
- Webhook de produção do n8n e Bearer Token.
- Timeout do workflow.
- Permitir/bloquear fallback para o parser PHP local.
- Endpoint/token do serviço Extrator OS.
- Azure Document Intelligence endpoint/key/model/API version.
- Endpoint/token/model da IA visual e toggle de fallback.

Os segredos são persistidos em `integrations.local.php`, ignorado pelo Git e gravado com permissão restrita.

## Requisição CRM → n8n

O CRM envia `multipart/form-data`:

- `arquivo_pdf`: PDF original.
- `schema_version`: `dnb-os-72-v1`.
- `schema_json`: lista oficial dos 72 campos com `key`, `label` e `section`.
- `expected_fields`: `72`.
- `request_id`: identificador aleatório da análise.

Headers principais:

- `Authorization: Bearer <token n8n>`
- `X-DNB-Schema-Version: dnb-os-72-v1`
- `X-DNB-Public-Gateway: https://crm.dnbair.com.br/os/analisar`
- `X-DNB-Extractor-URL`
- `X-DNB-Extractor-Token`
- `X-DNB-Azure-Endpoint`
- `X-DNB-Azure-Key`
- `X-DNB-Azure-Model`
- `X-DNB-Azure-Api-Version`
- `X-DNB-AI-Visual-Enabled`
- `X-DNB-AI-Visual-Endpoint`
- `X-DNB-AI-Visual-Token`
- `X-DNB-AI-Visual-Model`

Os headers opcionais só são enviados quando possuem valor configurado.

## Resposta n8n → CRM

O workflow deve devolver JSON compacto. Não devolva o resultado bruto inteiro do Azure.

```json
{
  "ok": true,
  "extractor": "os-extractor-v1",
  "azure_status": "succeeded",
  "fields": {
    "os": "12345",
    "nome_cnpj": "Empresa Exemplo 00.000.000/0001-00",
    "pessoa_contato": "Maria",
    "equipamento_operando": "Sim",
    "fabricante": "Ingersoll Rand",
    "modelos": "R11",
    "oportunidade_comercial": "Contrato anual de manutenção"
  },
  "events": [
    {"hora":"08:00","evento":"Chegada ao cliente","tipo":"Visita"}
  ],
  "confidence": {
    "os": 0.99,
    "nome_cnpj": 0.98,
    "fabricante": 0.95
  },
  "empty": ["foto_4"],
  "unresolved": ["tipo_oleo"],
  "photos": [
    {
      "slot": "foto_1",
      "name": "compressor.jpg",
      "page": 2,
      "caption": "Compressor principal",
      "confidence": 0.94
    }
  ]
}
```

`fields` pode usar as chaves canônicas do CRM ou os rótulos do PDF; o gateway normaliza ambos. Valores retornados pelo n8n têm prioridade. O parser PHP local é usado apenas para preencher lacunas.

## Regras de qualidade

- Nunca inventar conteúdo para completar 72/72.
- Campo sem informação visível deve ser listado em `empty`.
- Campo que existe mas não foi possível interpretar com segurança deve ficar em `unresolved`.
- Use `confidence` de 0 a 1 por campo sempre que o provedor disponibilizar confiança.
- `photos` nesta primeira etapa descreve as imagens detectadas. O upload binário e a galeria persistente serão conectados ao Extrator OS na etapa de fotos.
- Ler PDF não cria visita nem oportunidade. A persistência continua exclusiva do botão **Registrar visita e abrir oportunidade**.

## Azure Document Intelligence

Para novo desenvolvimento usar a API v4.0 `2024-11-30`, modelo padrão `prebuilt-layout`. O workflow deve iniciar a análise, acompanhar a URL de operação retornada pelo Azure até `succeeded`/`failed` e transformar o resultado para o contrato acima.

O modelo de layout deve ser usado para texto, estrutura, tabelas e selection marks. Query Fields e IA visual podem ser usados para os campos que permanecerem não resolvidos.

## Ordem recomendada do workflow n8n

1. Webhook `POST /os-analisar-v1`.
2. Validar Bearer Token e `schema_version`.
3. Enviar o PDF ao **Extrator OS** para texto nativo, imagens, campos PDF e páginas renderizadas.
4. Enviar documento/páginas ao **Azure Document Intelligence**.
5. Mapear Azure + Extrator para as 72 chaves recebidas em `schema_json`.
6. Identificar `empty`, `unresolved` e confiança.
7. Se IA visual estiver habilitada, enviar somente páginas/campos não resolvidos para o fallback visual.
8. Consolidar `fields`, `events`, `confidence`, `empty`, `unresolved`, `photos`.
9. Respond to Webhook com o JSON compacto.

### Segurança

O webhook do n8n deve aceitar somente HTTPS e Bearer Token. Evite salvar payloads completos contendo chaves nos execution logs. O MySQL do cPanel não deve ser aberto para a VPS: toda comunicação volta ao CRM por HTTPS.
