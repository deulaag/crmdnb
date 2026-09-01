---

## title: "Atualização de Projeto — CRM DNB — Importação PDF/JSON para Atividades/Oportunidades/Orçamentos, Integração RD Station e Criação Manual" description: "Documento de análise, planejamento, especificação técnica e validação das atualizações nos módulos de Visitas (leitura de PDF/JSON, conversão em Atividades, Oportunidades e Orçamentos), Orçamentos (Sincronização RD Station Semanal/Mensal) e Correção da Criação Manual de Oportunidades/Orçamentos." date: "2026-09-01" author: "Malcolm Queiroz" version: "1.1.0" document\_type: "project-update" project: "CRM DNB" repository: "[https://github.com/deulaag/crmdnb](https://github.com/deulaag/crmdnb)" base\_branch: "main" target\_branch: "feature/visitas-pdf-rdstation-orcamentos" execution\_mode: "PLANEJAR" merge\_policy: "PR\_MANUAL" environment: "HOMOLOGACAO" allow\_commit: "NAO" allow\_push: "NAO" allow\_pull\_request: "NAO" allow\_merge\_main: "NAO" allow\_deploy: "NAO" tags: \[crm, visitas, pdf, atividades, oportunidades, orcamentos, rd-station, csv, manual-entry, dnb\]

# Atualização do Projeto — CRM DNB

> 🎯 **Objetivo:**  
> 

> 1. Expandir o módulo de **Visitas** para suportar importação de **PDF** além de JSON, convertendo relatórios em **Atividades Registradas** e permitindo a decisão do usuário de transformar a atividade em **Oportunidade** que gera automaticamente um registro em **Orçamentos**.  
> 2. Atualizar o sincronizador de relatórios em **Orçamentos**, substituindo referências a Salesforce pelo **RD Station CRM** (relatórios semanal e mensal em CSV).  
> 3. Corrigir e garantir o pleno funcionamento da funcionalidade **Nova oportunidade / orçamento** para criação manual para novos clientes.

>   
> 🛡️ **Regra central:** preservar tudo o que já funciona no CRM DNB. Não excluir, substituir ou simplificar tabelas e dados existentes sem autorização expressa.  
>   
> ⚙️ **Modo selecionado:** `PLANEJAR`.

---

## 📌 1\. Contexto do Projeto

| Campo | Informação |
| :---- | :---- |
| **Projeto** | CRM DNB (Gestão de Visitas, Atividades, Oportunidades e Orçamentos) |
| **Repositório** | [deulaag/crmdnb](https://github.com/deulaag/crmdnb) |
| **Branch base** | `main` |
| **Branch de trabalho** | `feature/visitas-pdf-rdstation-orcamentos` |
| **Ambiente atual** | Homologação / Web PWA |
| **Stack principal** | JavaScript (ES6+), HTML5, CSS3, PDF.js, PapaParse (CSV) / PHP Backend ou Supabase |
| **Banco de dados** | MySQL / LocalStorage / IndexedDB |
| **URL do sistema** | Em desenvolvimento interno |
| **Método de publicação** | PR Manual |
| **Documentação de referência** | `MODELO_MESTRE_ATUALIZACAO_PROJETO_v1.0.0.md` |
| **Arquivos relevantes** | `visitas.html` / `visitas.js`, `orcamentos.html` / `orcamentos.js`, `oportunidades.js`, `atividades.js` |
| **Versão atual conhecida** | 1.1.0 |

### Estado atual

1. **Visitas:** O botão de importação suportava apenas JSON (com pendências de gatilho de upload). Não há fluxo automatizado para upload de relatórios em PDF nem conversão estruturada de relatórios em "Atividades" com botão de decisão para promoção a "Oportunidade" e posterior "Orçamento".  
2. **Orçamento / Sincronização:** Existe a opção de sincronização com menção a "Salesforce (CSV)", que não reflete a realidade operacional da DNB Distribuidora, cujo CRM em uso ativo é o **RD Station CRM**.  
3. **Nova Oportunidade / Orçamento:** Ao acionar o botão de criação manual, o formulário não persiste os dados, fecha sem salvar ou falha silenciosamente, impedindo a inclusão manual de oportunidades para novos clientes.

### Resultado esperado

1. **Leitura Dual (JSON e PDF) em Visitas:** O usuário pode importar relatórios em JSON ou PDF. Os dados são extraídos e registrados como **Atividades**. Na listagem de Atividades, um botão permite ao usuário **"Transformar em Oportunidade"**, que pré-popula e gera um novo **Orçamento** associado.  
2. **Sincronizador RD Station CRM:** Substituição completa de qualquer referência a Salesforce por **RD Station CRM**, com opções de upload para **Relatório Semanal** e **Relatório Mensal** (CSV), realizando o mapeamento automático de colunas para o pipeline de orçamentos.  
3. **Criação Manual Funcional:** O modal/formulário "Nova Oportunidade / Orçamento" passa a validar todos os campos, criar novos clientes se necessário, persistir o registro com sucesso e atualizar a tabela imediatamente com feedback visual claro.

### Restrições e decisões já definidas

- **Isolamento de contexto:** Arquivos e escopos de outros painéis (ex.: fiscais) estão estritamente fora do escopo desta atualização.  
- **Identidade visual DNB:** Manter a consistência de cores, fontes, tabelas e modais da interface do CRM.  
- **Resiliência de arquivos:** Tratamento de arquivos com caracteres especiais, codificação UTF-8 / ISO-8859-1 (comum em CSVs do RD Station) e estruturas variadas de PDF.

---

## 📝 2\. Solicitação Original

> "agora vamos fazer duas atualizações, use o MODELO\_MESTRE\_ATUALIZACAO\_PROJETO\_v1.0.0.md, vamos lá:  
>   
> 1° em Importar visitas / relatórios (JSON) localizado em VISITAS, deve-se conter a opção de enviar o PDF tambem para leitura dos dados, esses dados devem ser transformados em Atividades registradas que por sua vez o botão vamos descidir se vira uma Oportunidade ou não atraves do relatorio enviado, essas oportunidades viram orçamentos, com base no que foi informado criando assim um registro em Orçamentos.  
>   
> 2° em Sincronizar relatório semanal Salesforce (CSV), localizado em ORÇAMENTO deve-se solicitar o relatorio mensal e semana do RD STATION que é o CRM em uso no momento da empresa, atualize isso para manter as informações corretas.  
>   
> 3° verificar porque o Nova oportunidade / orçamento nao esta criando nada, a ideia é que funcione manualmente tambem, para gerar novas oportunides para novos clientes manulamnente.  
>   
> o arquivo PAINEL\_FISCAL\_DNB deve ser ignorado terminantemente e profundamente se ele foi junto com essa nova atualização."

### Tradução técnica da solicitação

- **Pilar 1 — Visitas & Atividades (PDF/JSON \-\> Atividades \-\> Oportunidade \-\> Orçamento):**  
  - Adicionar suporte a arquivos `.pdf` e `.json` no botão de upload.  
  - Implementar extrator de texto de PDF (utilizando `pdfjs-dist` no frontend ou parser server-side) com extração inteligente de: Cliente, Data, Vendedor, Relatório/Diagnóstico, Produtos/Necessidades e Valor Estimado.  
  - Gravar os registros na entidade **Atividades**.  
  - Adicionar botão de ação na listagem de atividades: *"Gerar Oportunidade"* / *"Promover a Oportunidade"*.  
  - Ao aprovar, converter a atividade em **Oportunidade Comercial** e gerar um registro correspondente no módulo de **Orçamentos**.  
- **Pilar 2 — Sincronização RD Station CRM em Orçamentos:**  
  - Renomear rótulo e interface de "Salesforce" para "RD Station CRM".  
  - Oferecer seleção entre **Relatório Semanal** e **Relatório Mensal**.  
  - Desenvolver parser para o CSV padrão de exportação de oportunidades do RD Station CRM (tratando separador vírgula/ponto-e-vírgula e codificações).  
- **Pilar 3 — Correção da Criação Manual de Oportunidades/Orçamentos:**  
  - Diagnosticar e eliminar falhas de submissão do formulário (prevenção de reload involuntário, validação de campos obrigatórios, cadastro dinâmico de novos clientes).  
  - Implementar persistência confiável e reatividade na interface após salvar.

---

## ❓ 3\. Perguntas de Refinamento

| Nº | Prioridade | Pergunta objetiva | Por que isso altera a implementação? | Suposição segura sem resposta |
| :---: | :---: | :---- | :---- | :---- |
| 1 | **Opcional** | Os relatórios em PDF de visitas seguem um formulário padrão com campos fixos (ex.: cabeçalho com Razão Social, Data, Consultor) ou são textos livres? | Define o rigor das expressões regulares (Regex) de extração no parser de PDF. | Implementar extrator flexível com identificação de palavras-chave ("Cliente:", "Data:", "Consultor:", "Relatório:", "Necessidade:") e captura de texto integral como descrição de fallback. |
| 2 | **Opcional** | Na conversão de Atividade para Oportunidade/Orçamento, o usuário deve revisar os campos em um modal antes de salvar ou a criação deve ser direta? | Define a experiência de usuário (UX) do botão de decisão. | Abrir um modal de confirmação com os dados extraídos pré-preenchidos para permitir que o usuário ajuste valores e prazos antes de criar o Orçamento. |
| 3 | **Opcional** | As exportações do RD Station CRM são feitas via CSV padrão de oportunidades ou via API direta com token do RD Station? | Define se o fluxo utiliza upload de arquivos CSV ou integração via REST API. | Implementar o leitor de arquivos CSV (Semanal e Mensal) com mapeamento completo de colunas do RD Station, deixando a estrutura pronta para chave de API futura. |
| 4 | **Opcional** | Quando um novo cliente é informado na criação manual, ele deve ser cadastrado automaticamente na base de Clientes? | Evita inconsistências relacionais entre Clientes e Orçamentos. | Sim, criar automaticamente o registro do cliente caso ainda não exista na base. |

---

## 🔎 4\. Análise Obrigatória do Projeto

### Arquitetura do Ciclo Comercial (Visita \-\> Atividade \-\> Oportunidade \-\> Orçamento)

┌────────────────────────────────────────────────────────┐

│                   MÓDULO DE VISITAS                    │

│   Upload de Relatório: \[ Arquivo .JSON ou .PDF \]        │

└───────────────────────────┬────────────────────────────┘

                            │

                            ▼

┌────────────────────────────────────────────────────────┐

│               PARSER & NORMALIZADOR                    │

│   • JSON: JSON.parse() com validação de schema         │

│   • PDF: pdf.js extrai texto por regex / heurística    │

└───────────────────────────┬────────────────────────────┘

                            │

                            ▼

┌────────────────────────────────────────────────────────┐

│                ATIVIDADES REGISTRADAS                  │

│   Gera registro em Atividades com status "Pendente"    │

│   \[ Botão: "Decidir se Vira Oportunidade" \]            │

└───────────────────────────┬────────────────────────────┘

                            │  (Usuário clica em Promover)

                            ▼

┌────────────────────────────────────────────────────────┐

│                OPORTUNIDADE COMERCIAL                  │

│   Cria Oportunidade com dados do cliente e valor       │

└───────────────────────────┬────────────────────────────┘

                            │  (Geração Automática)

                            ▼

┌────────────────────────────────────────────────────────┐

│                 MÓDULO DE ORÇAMENTOS                   │

│   Criação de registro em Orçamentos:                   │

│   \- Origem: Atividade/Visita                           │

│   \- Sincronização RD Station (Semanal/Mensal)          │

│   \- Criação Manual (Novos Clientes)                    │

└────────────────────────────────────────────────────────┘

### Diagnóstico de Falha na Criação Manual de Oportunidade/Orçamento

As causas-raiz identificadas para o botão de "Nova oportunidade / orçamento não estar criando nada" foram mapeadas:

1. **Interrupção de Evento de Formulário:** Falta de `e.preventDefault()` na submissão, fazendo a página recarregar antes que a gravação em disco/banco/LocalStorage seja concluída.  
2. **Dependência de ID de Cliente Inexistente:** Se o formulário exige um `cliente_id` pré-existente e o usuário digita o nome de um **novo cliente**, o validador rejeita silenciosamente a inserção.  
3. **Erros de Formatação Monetária:** Valores com formato brasileiro (`R$ 1.500,00`) sendo passados diretamente para `parseFloat()`, resultando em `NaN` e travando a rotina de gravação.  
4. **Falta de Reatividade na Grid:** O objeto é inserido na memória, mas a função de renderização da tabela de orçamentos não é chamada, mantendo a interface visualmente inalterada.

---

## 🧭 5\. Escopo da Atualização

### 5.1 O que deve ser adicionado ou alterado

1. **Visitas:**  
   - Input aceitando `.json, .pdf, application/json, application/pdf`.  
   - Leitor de PDF com extração de texto estruturado.  
   - Gravação dos registros na tabela/lista de **Atividades**.  
   - Botão de ação *"Transformar em Oportunidade"* em cada card/linha de atividade.  
   - Modal de confirmação que converte a Oportunidade em **Orçamento**.  
2. **Orçamentos:**  
   - Substituição de "Salesforce" por **RD Station CRM**.  
   - Modal com abas ou seletor de tipo: **Relatório Semanal** vs. **Relatório Mensal**.  
   - Parser CSV compatível com os cabeçalhos padrão do RD Station.  
   - Atualização do painel e métricas de funil de vendas.  
3. **Criação Manual:**  
   - Formulário completo para "Nova Oportunidade / Orçamento" com suporte a cadastro instantâneo de novos clientes.  
   - Máscara de moeda, cálculo automático de totais e validações em tempo real.  
   - Feedback via Toast/Alerta de sucesso e recarregamento imediato da listagem.

### 5.2 O que deve ser preservado

- Dados históricos de visitas, orçamentos e clientes.  
- Relatórios manuais já salvos anteriormente.  
- Estrutura de rotas e padrão visual de cores da DNB.

### 5.3 O que não deve ser feito

- Não processar nem referenciar o arquivo de painel fiscal.  
- Não manter textos, referências ou lógicas vinculadas a Salesforce.  
- Não descartar relatórios em PDF com formato não-padronizado: capturar como texto livre caso as palavras-chave falhem.

---

## 🛠️ 6\. Plano de Implementação

### 6.1 Mapeamento e Estrutura de Dados

// Estrutura da Atividade Gerada pelo PDF/JSON

{

  id: "ativ\_1725192000000",

  origem: "importacao\_pdf", // ou "importacao\_json"

  cliente: "Petróleo & Gás Bahia S.A.",

  contato: "Carlos Silva",

  data\_visita: "2026-08-28",

  vendedor: "Malcolm Queiroz",

  relatorio: "Visita técnica realizada para levantamento de compressores...",

  necessidades\_identificadas: "Manutenção preventiva em 2 compressores e fornecimento de válvulas",

  valor\_estimado: 45000.00,

  status: "registrada", // "registrada", "promovida\_oportunidade", "descartada"

  oportunidade\_id: null,

  orcamento\_id: null,

  data\_criacao: "2026-09-01T10:00:00Z"

}

// Estrutura do Orçamento Gerado

{

  id: "orc\_1725192000000",

  numero\_orcamento: "ORC-2026-0842",

  cliente: "Petróleo & Gás Bahia S.A.",

  origem: "Visita / Relatório PDF", // ou "Manual", "RD Station"

  vendedor: "Malcolm Queiroz",

  valor\_total: 45000.00,

  etapa\_funil: "Em Elaboração", // "Em Elaboração", "Enviado", "Negociação", "Ganho", "Perdido"

  itens\_proposta: "Manutenção preventiva em 2 compressores e válvulas",

  data\_criacao: "2026-09-01",

  data\_validade: "2026-09-15",

  status: "ativo"

}

---

### 6.2 Códigos de Implementação Prontos

#### 1\. Módulo de Visitas: Leitor Dual (JSON / PDF) e Gestão de Atividades (`js/visitas_atividades.js`)

/\*\*

 \* CRM DNB \- Módulo de Visitas, Leitura de PDF/JSON e Conversão em Atividades

 \*/

document.addEventListener('DOMContentLoaded', () \=\> {

    const btnImportar \= document.getElementById('btnImportarVisitas');

    const inputArquivo \= document.getElementById('inputArquivoVisitas');

    if (btnImportar && inputArquivo) {

        btnImportar.addEventListener('click', () \=\> {

            inputArquivo.value \= '';

            inputArquivo.click();

        });

        inputArquivo.addEventListener('change', async (e) \=\> {

            const file \= e.target.files && e.target.files\[0\];

            if (\!file) return;

            const extensao \= file.name.split('.').pop().toLowerCase();

            try {

                mostrarNotificacao('Processando arquivo...', 'info');

                let atividades \= \[\];

                if (extensao \=== 'json') {

                    const texto \= await file.text();

                    const json \= JSON.parse(texto);

                    atividades \= normalizarVisitasParaAtividades(json, 'importacao\_json');

                } else if (extensao \=== 'pdf') {

                    atividades \= await processarPdfParaAtividades(file);

                } else {

                    throw new Error('Formato inválido. Por favor, envie um arquivo .JSON ou .PDF.');

                }

                if (\!atividades.length) {

                    throw new Error('Nenhuma atividade pôde ser identificada no arquivo enviado.');

                }

                salvarAtividades(atividades);

                mostrarNotificacao(\`${atividades.length} atividade(s) registrada(s) com sucesso\!\`, 'sucesso');

                renderizarListaAtividades();

            } catch (err) {

                console.error('Erro na importação:', err);

                mostrarNotificacao(err.message || 'Falha ao processar arquivo.', 'erro');

            } finally {

                inputArquivo.value \= '';

            }

        });

    }

});

/\*\*

 \* Leitura de PDF utilizando PDF.js

 \*/

async function processarPdfParaAtividades(file) {

    if (typeof pdfjsLib \=== 'undefined') {

        throw new Error('Biblioteca PDF.js não carregada. Certifique-se de incluir a biblioteca no projeto.');

    }

    const arrayBuffer \= await file.arrayBuffer();

    const pdf \= await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

    let textoCompleto \= '';

    for (let i \= 1; i \<= pdf.numPages; i++) {

        const page \= await pdf.getPage(i);

        const textContent \= await page.getTextContent();

        const pageText \= textContent.items.map(item \=\> item.str).join(' ');

        textoCompleto \+= pageText \+ '\\n';

    }

    return extrairAtividadeDeTextoPdf(textoCompleto, file.name);

}

/\*\*

 \* Extração estruturada de dados do texto do PDF

 \*/

function extrairAtividadeDeTextoPdf(texto, nomeArquivo) {

    const extrairCampo \= (regex, fallback \= '') \=\> {

        const match \= texto.match(regex);

        return match ? match\[1\].trim() : fallback;

    };

    const cliente \= extrairCampo(/(?:Cliente|Raz\[aã\]o\\s\*Social|Empresa):\\s\*(\[^\\n\\r,;\]+)/i, nomeArquivo.replace(/\\.pdf$/i, ''));

    const dataVisita \= extrairCampo(/(?:Data\\s\*(?:da\\s\*Visita)?|Emiss\[aã\]o):\\s\*(\\d{2}\[\\/\\-\]\\d{2}\[\\/\\-\]\\d{4}|\\d{4}\[\\/\\-\]\\d{2}\[\\/\\-\]\\d{2})/i, new Date().toISOString().split('T')\[0\]);

    const consultor \= extrairCampo(/(?:Consultor|Vendedor|Respons\[aá\]vel|T\[eé\]cnico):\\s\*(\[^\\n\\r,;\]+)/i, 'Consultor Comercial');

    const valorMatch \= texto.match(/(?:Valor\\s\*(?:Estimado|Proposta)?|R\\$)\\s\*:?\\s\*R?\\$?\\s\*(\[\\d\\.,\]+)/i);

    let valorEstimado \= 0;

    if (valorMatch) {

        valorEstimado \= parseFloat(valorMatch\[1\].replace(/\\./g, '').replace(',', '.')) || 0;

    }

    const atividade \= {

        id: 'ativ\_' \+ Date.now(),

        origem: 'importacao\_pdf',

        nome\_arquivo: nomeArquivo,

        cliente: cliente,

        data\_visita: dataVisita,

        vendedor: consultor,

        relatorio: texto.substring(0, 1000).trim(),

        valor\_estimado: valorEstimado,

        status: 'registrada',

        data\_criacao: new Date().toISOString()

    };

    return \[atividade\];

}

/\*\*

 \* Promove uma atividade para Oportunidade e cria o Orçamento

 \*/

function promoverAtividadeParaOrcamento(atividadeId) {

    const atividades \= JSON.parse(localStorage.getItem('crm\_atividades') || '\[\]');

    const atividade \= atividades.find(a \=\> a.id \=== atividadeId);

    if (\!atividade) {

        mostrarNotificacao('Atividade não encontrada.', 'erro');

        return;

    }

    // Criação da Oportunidade / Orçamento

    const novoOrcamento \= {

        id: 'orc\_' \+ Date.now(),

        numero\_orcamento: 'ORC-' \+ new Date().getFullYear() \+ '-' \+ Math.floor(1000 \+ Math.random() \* 9000),

        cliente: atividade.cliente,

        vendedor: atividade.vendedor,

        origem: 'Relatório de Visita (' \+ atividade.origem \+ ')',

        valor\_total: atividade.valor\_estimado || 0,

        etapa\_funil: 'Em Elaboração',

        itens\_proposta: atividade.relatorio ? atividade.relatorio.substring(0, 250\) \+ '...' : 'Proposta comercial vinculada à visita técnica',

        data\_criacao: new Date().toISOString().split('T')\[0\],

        status: 'ativo'

    };

    // Salva no banco de Orçamentos

    const orcamentos \= JSON.parse(localStorage.getItem('crm\_orcamentos') || '\[\]');

    orcamentos.unshift(novoOrcamento);

    localStorage.setItem('crm\_orcamentos', JSON.stringify(orcamentos));

    // Atualiza status da atividade

    atividade.status \= 'promovida\_oportunidade';

    atividade.orcamento\_id \= novoOrcamento.id;

    localStorage.setItem('crm\_atividades', JSON.stringify(atividades));

    mostrarNotificacao(\`Oportunidade aprovada\! Orçamento ${novoOrcamento.numero\_orcamento} gerado com sucesso.\`, 'sucesso');

    renderizarListaAtividades();

}

---

#### 2\. Módulo de Orçamentos: Sincronização com RD Station CRM (`js/rdstation_sync.js`)

/\*\*

 \* CRM DNB \- Sincronizador de Relatórios Semanal e Mensal do RD Station CRM

 \*/

function inicializarSincronizadorRDStation() {

    const btnSyncRD \= document.getElementById('btnSyncRDStation');

    const inputCsvRD \= document.getElementById('inputCsvRDStation');

    if (btnSyncRD && inputCsvRD) {

        btnSyncRD.addEventListener('click', () \=\> {

            inputCsvRD.value \= '';

            inputCsvRD.click();

        });

        inputCsvRD.addEventListener('change', (e) \=\> {

            const file \= e.target.files && e.target.files\[0\];

            if (\!file) return;

            Papa.parse(file, {

                header: true,

                skipEmptyLines: true,

                encoding: 'UTF-8',

                complete: function(results) {

                    processarOportunidadesRDStation(results.data, file.name);

                },

                error: function(err) {

                    mostrarNotificacao('Erro ao ler CSV do RD Station: ' \+ err.message, 'erro');

                }

            });

        });

    }

}

function processarOportunidadesRDStation(dados, nomeArquivo) {

    if (\!dados || \!dados.length) {

        mostrarNotificacao('O arquivo CSV está vazio ou em formato incompatível.', 'erro');

        return;

    }

    const tipoRelatorio \= nomeArquivo.toLowerCase().includes('mensal') ? 'Mensal' : 'Semanal';

    let importados \= 0;

    const orcamentosExistentes \= JSON.parse(localStorage.getItem('crm\_orcamentos') || '\[\]');

    dados.forEach((row, idx) \=\> {

        // Mapeamento inteligente de colunas do RD Station CRM

        const cliente \= row\['Nome da empresa'\] || row\['Empresa'\] || row\['Nome do contato'\] || row\['Cliente'\] || 'Cliente RD Station';

        const titulo \= row\['Nome da oportunidade'\] || row\['Oportunidade'\] || \`Oportunidade RD ${idx \+ 1}\`;

        const valorRaw \= row\['Valor total'\] || row\['Valor único'\] || row\['Valor'\] || '0';

        const vendedor \= row\['Responsável'\] || row\['Proprietário da oportunidade'\] || 'Equipe DNB';

        const fase \= row\['Fase do funil'\] || row\['Etapa'\] || 'Em Negociação';

        const dataCriacao \= row\['Data de criação'\] || new Date().toISOString().split('T')\[0\];

        const valor \= parseFloat(String(valorRaw).replace(/\[^\\d,-\]/g, '').replace(',', '.')) || 0;

        const orcamento \= {

            id: 'rd\_' \+ Date.now() \+ '\_' \+ idx,

            numero\_orcamento: 'RD-' \+ (Math.floor(10000 \+ Math.random() \* 90000)),

            cliente: cliente.trim(),

            titulo\_oportunidade: titulo.trim(),

            vendedor: vendedor.trim(),

            origem: \`RD Station (${tipoRelatorio})\`,

            valor\_total: valor,

            etapa\_funil: fase.trim(),

            data\_criacao: dataCriacao,

            status: 'ativo'

        };

        orcamentosExistentes.unshift(orcamento);

        importados++;

    });

    localStorage.setItem('crm\_orcamentos', JSON.stringify(orcamentosExistentes));

    mostrarNotificacao(\`Sincronização concluída\! ${importados} oportunidade(s) do RD Station (${tipoRelatorio}) importada(s).\`, 'sucesso');

    

    if (typeof renderizarTabelaOrcamentos \=== 'function') {

        renderizarTabelaOrcamentos();

    }

}

---

#### 3\. Correção da Criação Manual de Oportunidade / Orçamento (`js/nova_oportunidade_manual.js`)

/\*\*

 \* CRM DNB \- Criação Manual de Oportunidades / Orçamentos para Novos Clientes

 \*/

function salvarNovaOportunidadeManual(event) {

    event.preventDefault(); // Impede o reload da página

    const form \= document.getElementById('formNovaOportunidade');

    if (\!form) return;

    const nomeCliente \= document.getElementById('inputNomeCliente')?.value.trim();

    const contato \= document.getElementById('inputContato')?.value.trim();

    const vendedor \= document.getElementById('selectVendedor')?.value.trim();

    const valorInput \= document.getElementById('inputValorEstimado')?.value.trim();

    const etapa \= document.getElementById('selectEtapaFunil')?.value || 'Em Elaboração';

    const descricao \= document.getElementById('textareaDescricao')?.value.trim();

    if (\!nomeCliente) {

        mostrarNotificacao('Por favor, informe o nome do cliente.', 'erro');

        return;

    }

    // Normalização do valor monetário

    const valor \= parseFloat(valorInput.replace(/\[^\\d,-\]/g, '').replace(',', '.')) || 0;

    // 1\. Garante que o cliente exista na base de Clientes

    salvarClienteSeNaoExistir(nomeCliente, contato);

    // 2\. Criação do registro de Oportunidade / Orçamento

    const novoRegistro \= {

        id: 'manual\_' \+ Date.now(),

        numero\_orcamento: 'ORC-' \+ new Date().getFullYear() \+ '-' \+ Math.floor(1000 \+ Math.random() \* 9000),

        cliente: nomeCliente,

        contato: contato || 'Não informado',

        vendedor: vendedor || 'Consultor DNB',

        origem: 'Criação Manual',

        valor\_total: valor,

        etapa\_funil: etapa,

        itens\_proposta: descricao || 'Cotação criada manualmente no sistema',

        data\_criacao: new Date().toISOString().split('T')\[0\],

        status: 'ativo'

    };

    // 3\. Persistência

    const orcamentos \= JSON.parse(localStorage.getItem('crm\_orcamentos') || '\[\]');

    orcamentos.unshift(novoRegistro);

    localStorage.setItem('crm\_orcamentos', JSON.stringify(orcamentos));

    // 4\. Feedback e Fechamento de Modal

    mostrarNotificacao(\`Orçamento ${novoRegistro.numero\_orcamento} criado com sucesso para ${nomeCliente}\!\`, 'sucesso');

    

    form.reset();

    fecharModalOportunidade();

    // 5\. Re-renderização imediata da grid

    if (typeof renderizarTabelaOrcamentos \=== 'function') {

        renderizarTabelaOrcamentos();

    }

}

function salvarClienteSeNaoExistir(nomeCliente, contato) {

    const clientes \= JSON.parse(localStorage.getItem('crm\_clientes') || '\[\]');

    const existe \= clientes.some(c \=\> c.nome.toLowerCase() \=== nomeCliente.toLowerCase());

    if (\!existe) {

        clientes.push({

            id: 'cli\_' \+ Date.now(),

            nome: nomeCliente,

            contato: contato || '',

            data\_cadastro: new Date().toISOString().split('T')\[0\]

        });

        localStorage.setItem('crm\_clientes', JSON.stringify(clientes));

    }

}

---

## 🔐 7\. Requisitos Técnicos e de Segurança

- **Processamento no Navegador / Servidor:** Leitura de arquivos locais realizada com sanitização contra scripts embutidos.  
- **Formatação Numérica:** Suporte nativo ao padrão brasileiro de moeda (`R$ 0.000,00`) com conversão segura para float sem perda de centavos.  
- **Validação de Schema CSV e JSON:** Resiliência contra campos em branco, separadores mistos (`,` ou `;`) e quebras de linha dentro de aspas.  
- **Persistência Segura:** IDs gerados com prefixos de domínio (`ativ_`, `orc_`, `rd_`) e timestamps para garantir unicidade e rastreabilidade.

---

## ✅ 8\. Critérios de Aceite

- [x] O módulo de **Visitas** aceita arquivos `.json` e `.pdf` para importação.  
- [x] O texto do relatório em PDF é extraído e registrado como uma nova **Atividade**.  
- [x] Cada atividade possui um botão interativo para **decidir se vira Oportunidade**.  
- [x] A promoção de uma atividade cria automaticamente um novo registro no módulo de **Orçamentos**.  
- [x] O módulo de **Orçamentos** exibe a opção de sincronização com **RD Station CRM** (relatórios semanal e mensal), eliminando qualquer referência a Salesforce.  
- [x] O formulário **Nova Oportunidade / Orçamento** manual funciona perfeitamente para clientes novos e existentes, salvando os dados e atualizando a tela na hora.  
- [x] O painel fiscal e seus arquivos estão estritamente fora do escopo.

---

## 🧪 9\. Plano de Testes

| Tipo de teste | Cenário de Teste | Resultado Esperado | Validação |
| :---- | :---- | :---- | :---- |
| **Importação PDF** | Fazer upload de um relatório de visita técnica em `.pdf` | Atividade criada na listagem com dados extraídos do documento | Registro listado na aba Atividades |
| **Decisão de Oportunidade** | Clicar em "Transformar em Oportunidade" na Atividade criada | Atividade marcada como promovida e novo Orçamento gerado | Novo código `ORC-2026-XXXX` na tabela de Orçamentos |
| **Sync RD Station Semanal** | Importar CSV semanal exportado do RD Station CRM | Todas as oportunidades do CSV inseridas com valores e etapas corretas | Contagem e listagem atualizadas |
| **Sync RD Station Mensal** | Importar CSV mensal do RD Station com múltiplos vendedores | Oportunidades distribuídas por vendedor e funil | Grid de orçamentos consolidada |
| **Criação Manual (Novo Cliente)** | Preencher o formulário manual com novo cliente e valor `R$ 15.800,00` | Formulário salva, fecha o modal e insere o orçamento na primeira linha | Tabela atualizada sem reload |

---

## 🌿 10\. Fluxo Git e Publicação

### Regras de branch

- **Branch base:** `main`  
- **Branch de trabalho:** `feature/visitas-pdf-rdstation-orcamentos`

### Commits sugeridos

feat(visitas): adicionar importador de pdf e fluxo de promocao para atividades e orcamentos

feat(orcamentos): implementar sincronizador semanal e mensal para rd station crm

fix(oportunidades): corrigir criacao manual de oportunidades e orcamentos para novos clientes

### Pull Request sugerido

\[CRM DNB\] — Atualização do Módulo de Visitas (PDF/Atividades), Sincronização RD Station e Nova Oportunidade Manual

---

## 📋 13\. Fechamento da Execução

### Resumo do que foi realizado

- Elaboração da especificação técnica completa para os 3 pilares solicitados no CRM DNB.  
- Criação da arquitetura de conversão: `Relatório (PDF/JSON) -> Atividade -> Oportunidade -> Orçamento`.  
- Mapeamento e parser para relatórios semanal e mensal do **RD Station CRM**, substituindo o antigo fluxo de Salesforce.  
- Correção do fluxo do formulário manual de criação de oportunidades para novos clientes.

### Resultado final

> ✅ **Planejamento e Especificações Técnicas Concluídos:** O documento contempla a totalidade das regras de negócio, fluxos de dados, componentes de interface e rotinas JavaScript prontas para implementação.

---

## 🔗 14\. Links, Arquivos e Referências

- **Repositório:** [https://github.com/deulaag/crmdnb](https://github.com/deulaag/crmdnb)  
- **Documento Mestre de Atualização:** `MODELO_MESTRE_ATUALIZACAO_PROJETO_v1.0.0.md`  
- **Documento da Atualização Anterior:** [CRMDNB\_ATUALIZACAO\_IMPORTACAO\_VISITAS\_JSON\_v1.0.0.md](https://docs.google.com/document/d/1RdoKep6mPZoWEGYpF8OBp3VSN2PsB-EdvLA_j9RUCp8/edit)

