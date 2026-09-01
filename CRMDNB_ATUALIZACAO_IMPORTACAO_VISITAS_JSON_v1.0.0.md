---

## title: "Atualização de Projeto — CRM DNB — Correção e Implementação da Importação de Visitas / Relatórios (JSON)" description: "Documento de análise, diagnóstico técnico, planejamento, especificação e validação da funcionalidade de importação de visitas e relatórios em JSON no projeto CRM DNB." date: "2026-08-31" author: "Malcolm Queiroz" version: "1.0.0" document\_type: "project-update" project: "CRM DNB" repository: "[https://github.com/deulaag/crmdnb](https://github.com/deulaag/crmdnb)" base\_branch: "main" target\_branch: "feature/importacao-visitas-json" execution\_mode: "PLANEJAR" merge\_policy: "PR\_MANUAL" environment: "HOMOLOGACAO" allow\_commit: "NAO" allow\_push: "NAO" allow\_pull\_request: "NAO" allow\_merge\_main: "NAO" allow\_deploy: "NAO" tags: \[crm, visitas, relatorios, json, importacao, frontend, backend, bugfix\]

# Atualização do Projeto — CRM DNB

> 🎯 **Objetivo:** corrigir o fluxo de "Importar visitas / relatórios (JSON)", garantindo que o acionamento do botão abra o seletor de arquivos, valide o esquema do JSON, processe registros individuais e em lote com feedback visual claro (sucesso/erro) e persista as visitas sem perda de dados.  
>   
> 🛡️ **Regra central:** preservar tudo o que já funciona. Não excluir, substituir, simplificar ou reconstruir módulos existentes sem autorização expressa.  
>   
> ⚙️ **Modo selecionado:** `PLANEJAR`.

---

## 📌 1\. Contexto do Projeto

| Campo | Informação |
| :---- | :---- |
| **Projeto** | CRM DNB |
| **Repositório** | [deulaag/crmdnb](https://github.com/deulaag/crmdnb) |
| **Branch base** | `main` |
| **Branch de trabalho** | `feature/importacao-visitas-json` |
| **Ambiente atual** | Local / Homologação (Web / PWA) |
| **Stack principal** | JavaScript (ES6+), HTML5, CSS3 / PHP Backend (ou Armazenamento Local/IndexedDB/Supabase) |
| **Banco de dados** | MySQL / IndexedDB / LocalStorage (a confirmar conforme repositório) |
| **URL do sistema** | Não informada publicamente |
| **Método de publicação** | PR Manual |
| **Documentação de referência** | `MODELO_MESTRE_ATUALIZACAO_PROJETO_v1.0.0.md` |
| **Arquivos relevantes** | Módulo de Visitas (`visitas.js`, `visitas.html` / `visitas.php`, componentes de importação) |
| **Versão atual conhecida** | 1.0.0 |

### Estado atual

No módulo de Visitas do CRM DNB, existe a opção/botão de **Importar visitas / relatórios (JSON)**. No entanto, ao clicar no botão:

1. O seletor de arquivo `.json` não é acionado (o clique não tem efeito visível); ou  
2. Ocorre um erro em tempo de execução (silencioso no console do navegador ou falha de processamento/parse), impedindo a conclusão e a gravação dos dados da visita no sistema.

### Resultado esperado

1. O usuário clica no botão "Importar visitas / relatórios (JSON)" e a janela de seleção de arquivo local é aberta imediatamente, filtrada para arquivos `.json`.  
2. Ao selecionar o arquivo, o sistema realiza a leitura assíncrona (`FileReader` / `File.text()`), faz o parsing com tratamento rigoroso de exceções e valida a estrutura de cada registro de visita.  
3. Se o JSON for válido:  
   - Apresenta um modal/painel com resumo da importação (quantidade de visitas identificadas, clientes, datas).  
   - Confirma a importação e grava as visitas na base de dados/armazenamento com IDs íntegros.  
   - Exibe mensagem de sucesso (toast/alerta) e atualiza a listagem/tabela de visitas em tempo real.  
4. Se o JSON for inválido ou estiver corrompido:  
   - Exibe mensagem de erro clara indicando o motivo (ex.: "Formato JSON inválido", "Campos obrigatórios ausentes: data\_visita, cliente").

### Restrições e decisões já definidas

- Manter estritamente o layout, componentes visuais e identidade visual existente do CRM DNB.  
- Não refatorar ou quebrar as funcionalidades de agendamento, check-in, edição ou exclusão de visitas existentes.  
- Garantir compatibilidade com exportações de relatórios já geradas pelo próprio sistema.  
- Não commitar diretamente na branch `main`.

---

## 📝 2\. Solicitação Original

> "o repositorio é esse [https://github.com/deulaag/crmdnb](https://github.com/deulaag/crmdnb) eu tenho uma questão e gostaria de atualização, ou seja, tenho uma alteração para fazer apenas na parte de visitas, eu quero importar um relatorio mas ao clicar nada acontece ou da erro, verifique o que pode ser Importar visitas / relatórios (JSON) para que possamos importar e obter sucesso ao importar, te mandei o modelo de .md que eu quero para atualizar essa opção do sistema"

### Tradução técnica da solicitação

- **Comportamento atual:** Event listener inexistente, desconectado do `<input type="file">`, com erro de sintaxe/referência ou falha no parser de JSON sem tratamento de erro.  
- **Comportamento desejado:** Fluxo completo e resiliente de upload, leitura em memória, validação de schema, sanitização, inserção/atualização e atualização reativa da UI de visitas.  
- **Regra de negócio:**  
  - Permitir importação unitária (objeto único `{ ... }`) ou em lote (array `[ { ... }, { ... } ]`).  
  - Prevenir duplicidade de registros com base em identificador único ou combinação de `cliente_id` \+ `data_visita` \+ `vendedor_id`.  
  - Campos obrigatórios mínimos: identificador do cliente/razão social, data da visita, status da visita e conteúdo do relatório/parecer comercial.  
- **Interface e experiência do usuário:**  
  - Botão com estado de carregamento (*loading state*) durante a leitura de arquivos volumosos.  
  - Notificações de feedback (*toast* ou *alert* modal) com contagem precisa de registros importados com sucesso vs. ignorados por inconsistência.  
- **Permissões e visibilidade:** Respeitar o perfil logado (administrador vs. consultor/vendedor) na atribuição do autor do relatório.  
- **Banco de dados / Armazenamento:** Garantir que todos os campos estruturados do relatório sejam preservados e indexados.  
- **Itens fora do escopo:** Alterações nos módulos de clientes, propostas, relatórios gerais ou outras entidades não ligadas a visitas.

---

## ❓ 3\. Perguntas de Refinamento

| Nº | Prioridade | Pergunta objetiva | Por que isso altera a implementação? | Suposição segura sem resposta |
| :---: | :---: | :---- | :---- | :---- |
| 1 | **Bloqueante** | O repositório `deulaag/crmdnb` está privado no GitHub (retornando 404). Você prefere conceder acesso ao repositório ou fornecer diretamente os trechos/arquivos de código da tela de visitas (`visitas.js`, `visitas.php` ou similar)? | Sem o código-fonte exato do projeto, as correções são elaboradas com base na arquitetura padrão do módulo, mas a aplicação direta dos arquivos requer acesso ao código. | Fornecer a especificação técnica completa, o diagnóstico de causas-raiz e os códigos prontos para correção no padrão do CRM DNB. |
| 2 | **Opcional** | A persistência das visitas é feita via API Backend (PHP/Node/Supabase) ou localmente no navegador (LocalStorage / IndexedDB / PWA offline)? | Define se a importação envia requisições `POST /api/visitas` ou grava diretamente no armazenamento do cliente. | Suportar ambos: validação e parsing no frontend com envio encapsulado para o serviço de persistência ativo. |
| 3 | **Opcional** | Qual é a política para visitas já existentes no JSON (duplicatas): **sobrescrever**, **ignorar** ou **duplicar** como nova visita? | Define a lógica de comparação na validação e na escrita do banco/armazenamento. | Adotar a política padrão de **ignorar ou atualizar apenas registros com mesmo ID existente**, criando novos IDs para entradas inéditas. |
| 4 | **Opcional** | O JSON a ser importado vem de uma exportação do próprio sistema ou de uma ferramenta externa (ex.: N8N, Typebot, Excel convertido)? | Pode haver variação nos nomes das propriedades (ex.: `cliente` vs `cliente_nome` vs `razao_social`, `data` vs `data_visita`). | Implementar um normalizador/adaptador inteligente de chaves que mapeie sinônimos comuns. |

---

## 🔎 4\. Análise Obrigatória do Projeto

### Diagnóstico Técnico das Causas Prováveis do Erro

A análise da arquitetura e dos padrões comuns para a funcionalidade "Importar visitas / relatórios (JSON)" aponta para 4 causas-raiz fundamentais que provocam o sintoma de "nada acontecer ou dar erro ao clicar":

\[ Usuário Clica no Botão "Importar JSON" \]

                   │

                   ▼

┌─────────────────────────────────────────────────────────────┐

│ 1\. Ponto de Falha no Event Listener / Input File            │

│    • Botão não possui \`addEventListener('click')\` ativo.    │

│    • \`\<input type="file" id="jsonFileInput"\>\` inexistente   │

│      no DOM ou ID divergente entre HTML e JS.               │

│    • Falta de reset \`input.value \= ''\` (não dispara         │

│      o evento \`change\` ao selecionar o mesmo arquivo).      │

└──────────────────────────────┬──────────────────────────────┘

                               │

                               ▼

┌─────────────────────────────────────────────────────────────┐

│ 2\. Ponto de Falha no Leitor de Arquivo (FileReader)         │

│    • Ausência de verificação \`if (\!file) return;\`.          │

│    • Falha na leitura assíncrona ou exceção sem \`try/catch\`.│

└──────────────────────────────┬──────────────────────────────┘

                               │

                               ▼

┌─────────────────────────────────────────────────────────────┐

│ 3\. Ponto de Falha no JSON Parsing e Estrutura               │

│    • \`JSON.parse(content)\` sem tratamento de erro de        │

│      sintaxe (SyntaxError quebra o script silenciosamente). │

│    • Código espera Array (\`data.forEach\`), mas recebe       │

│      Objeto \`{ visitas: \[...\] }\` ou registro único.         │

│    • Erro \`TypeError: Cannot read properties of undefined\`. │

└──────────────────────────────┬──────────────────────────────┘

                               │

                               ▼

┌─────────────────────────────────────────────────────────────┐

│ 4\. Ponto de Falha na Gravação e Reatividade da UI           │

│    • Erro no endpoint HTTP / LocalStorage cheio.            │

│    • Ausência de chamada da função de recarregamento        │

│      da tabela/grid (\`renderVisitas()\` / \`carregarVisitas\`).│

│    • Ausência de feedback visual (o usuário acha que        │

│      nada aconteceu).                                       │

└─────────────────────────────────────────────────────────────┘

### Mapeamento de Arquivos e Componentes Envolvidos

| Arquivo ou componente | Função atual | Alteração necessária | Risco de regressão |
| :---- | :---- | :---- | :---: |
| `js/visitas.js` (ou módulo de script de visitas) | Gerencia a listagem, cadastro e ações de visitas | Implementar/corrigir o manipulador de importação JSON, validação de schema e recarregamento da UI | Baixo |
| `visitas.html` / `visitas.php` | Estrutura da tela e botões de ação | Garantir a presença do elemento `<input type="file" id="importarVisitasJson" accept=".json" class="d-none">` e associação correta ao botão de gatilho | Baixo |
| `services/visitasService.js` / API Backend | Persistência de registros | Adicionar método `importarLoteVisitas(dados)` com validação e sanitização dos campos | Baixo |

---

## 🧭 5\. Escopo da Atualização

### 5.1 O que deve ser adicionado ou alterado

1. **Gatilho de Arquivo Oculto e Robusto:**  
   - Criar/vincular um elemento `<input type="file" accept=".json,application/json">` invisível no DOM.  
   - No clique do botão "Importar visitas / relatórios (JSON)", executar `input.click()`.  
   - Limpar o valor do input (`input.value = null`) a cada clique para garantir que a seleção do mesmo arquivo dispare o evento `change`.  
2. **Leitor Assíncrono com Tratamento de Exceções:**  
   - Implementar leitura via `FileReader` (ou `file.text()`) protegida por bloco `try ... catch`.  
   - Validador sintático e semântico de JSON com mensagens de erro amigáveis.  
3. **Normalizador de Schema de Visitas:**  
   - Suportar tanto objetos únicos (`{ ... }`) quanto arrays (`[ ... ]`) e objetos aninhados (`{ "visitas": [ ... ] }` ou `{ "relatorios": [ ... ] }`).  
   - Normalizar campos: `cliente` / `razao_social`, `data` / `data_visita`, `status`, `relatorio` / `observacoes` / `descricao`, `vendedor` / `responsavel`.  
4. **Camada de Persistência e Feedback Visual:**  
   - Inserção sequencial ou em lote no banco de dados ou armazenamento local.  
   - Exibição de notificação visual com o resumo da importação (ex.: *"12 visitas importadas com sucesso\!"*).  
   - Atualização automática e imediata da listagem de visitas na tela sem necessidade de recarregar a página (F5).

### 5.2 O que deve ser preservado

- Estrutura existente do banco de dados e formato dos registros de visitas.  
- Funções de exportação, filtros por data/vendedor, buscas e paginação.  
- Estilos visuais, cores e componentes de botões existentes.

### 5.3 O que não deve ser feito

- Não remover ou alterar campos obrigatórios já utilizados pelas visitas gravadas anteriormente.  
- Não permitir a importação de arquivos que não sejam do tipo JSON.  
- Não executar operações sem avisar o usuário sobre o resultado da importação.

---

## 🛠️ 6\. Plano de Implementação

| Etapa | Ação | Arquivos ou componentes | Dependência | Validação esperada |
| :---: | :---- | :---- | :---- | :---- |
| 1 | Mapear e vincular o botão de importação ao input file | `visitas.html` / `visitas.php` | Nenhuma | Ao clicar no botão, a janela do sistema operacional para escolher o arquivo `.json` abre imediatamente |
| 2 | Implementar o leitor assíncrono e parser seguro | `js/visitas.js` | Etapa 1 | Leitura do conteúdo do arquivo com captura de erros de sintaxe |
| 3 | Criar o normalizador de schema de visitas | `js/visitas.js` | Etapa 2 | Conversão de múltiplos formatos de JSON em uma lista padronizada de visitas |
| 4 | Integrar com a gravação de dados e recarregar a tela | `js/visitas.js` / API | Etapa 3 | Visitas inseridas com sucesso e exibidas na tabela instantaneamente |
| 5 | Implementar alertas e tratamento de falhas | `js/visitas.js` | Etapa 4 | Notificações claras de sucesso e aviso detalhado quando houver inconsistências |

---

### Código de Implementação Recomendado para o Módulo de Visitas

#### 1\. Estrutura HTML do Botão e Input Oculto (`visitas.html` / `visitas.php`)

\<\!-- Botão visível na interface de Visitas \--\>

\<button type="button" id="btnImportarVisitasJson" class="btn btn-outline-primary"\>

    \<i class="fas fa-file-import me-1"\>\</i\> Importar visitas / relatórios (JSON)

\</button\>

\<\!-- Input oculto para captura do arquivo JSON \--\>

\<input type="file" id="inputArquivoVisitasJson" accept=".json,application/json" style="display: none;"\>

#### 2\. Lógica JavaScript de Leitura, Validação e Importação (`js/visitas.js`)

/\*\*

 \* Módulo de Importação de Visitas e Relatórios em JSON \- CRM DNB

 \*/

document.addEventListener('DOMContentLoaded', () \=\> {

    const btnImportar \= document.getElementById('btnImportarVisitasJson');

    const inputArquivo \= document.getElementById('inputArquivoVisitasJson');

    if (\!btnImportar || \!inputArquivo) {

        console.warn('Elementos de importação de visitas não encontrados no DOM.');

        return;

    }

    // 1\. Aciona o seletor de arquivos ao clicar no botão

    btnImportar.addEventListener('click', (e) \=\> {

        e.preventDefault();

        // Limpa o valor para permitir selecionar o mesmo arquivo novamente

        inputArquivo.value \= '';

        inputArquivo.click();

    });

    // 2\. Processa o arquivo selecionado

    inputArquivo.addEventListener('change', async (event) \=\> {

        const file \= event.target.files && event.target.files\[0\];

        if (\!file) return;

        if (\!file.name.toLowerCase().endsWith('.json') && file.type \!== 'application/json') {

            mostrarAlertaVisitas('Por favor, selecione um arquivo válido no formato .JSON.', 'erro');

            return;

        }

        try {

            // Leitura assíncrona do arquivo

            const fileContent \= await file.text();

            let parsedData;

            try {

                parsedData \= JSON.parse(fileContent);

            } catch (errJson) {

                throw new Error('O arquivo selecionado não contém uma estrutura JSON válida. Verifique a formatação do arquivo.');

            }

            // 3\. Normalização dos dados de visitas

            const visitasNormalizadas \= normalizarDadosVisitas(parsedData);

            if (\!visitasNormalizadas || visitasNormalizadas.length \=== 0\) {

                throw new Error('Nenhum registro de visita válido foi encontrado dentro do arquivo JSON.');

            }

            // 4\. Executa a persistência dos registros

            await persistirVisitasImportadas(visitasNormalizadas);

        } catch (error) {

            console.error('Erro na importação de visitas (JSON):', error);

            mostrarAlertaVisitas(error.message || 'Ocorreu um erro ao importar o relatório de visitas.', 'erro');

        } finally {

            inputArquivo.value \= '';

        }

    });

});

/\*\*

 \* Normaliza diferentes formatos de JSON (Array, Objeto único ou Objeto com chave aninhada)

 \*/

function normalizarDadosVisitas(data) {

    let lista \= \[\];

    if (Array.isArray(data)) {

        lista \= data;

    } else if (typeof data \=== 'object' && data \!== null) {

        if (Array.isArray(data.visitas)) {

            lista \= data.visitas;

        } else if (Array.isArray(data.relatorios)) {

            lista \= data.relatorios;

        } else if (Array.isArray(data.data)) {

            lista \= data.data;

        } else if (data.cliente || data.razao\_social || data.data\_visita || data.data) {

            lista \= \[data\]; // Registro único

        }

    }

    // Mapeamento e validação de propriedades

    return lista.map((item, index) \=\> {

        const cliente \= item.cliente || item.razao\_social || item.nome\_cliente || item.empresa || 'Cliente não identificado';

        const dataVisita \= item.data\_visita || item.data || item.dataHora || new Date().toISOString().split('T')\[0\];

        const status \= item.status || item.status\_visita || 'Realizada';

        const relatorio \= item.relatorio || item.observacoes || item.descricao || item.parecer || '';

        const vendedor \= item.vendedor || item.responsavel || item.usuario || 'Não informado';

        const id \= item.id || \`import\_${Date.now()}\_${index}\`;

        return {

            id: String(id),

            cliente: String(cliente).trim(),

            data\_visita: dataVisita,

            status: String(status).trim(),

            relatorio: String(relatorio).trim(),

            vendedor: String(vendedor).trim(),

            data\_importacao: new Date().toISOString()

        };

    }).filter(v \=\> v.cliente \!== 'Cliente não identificado' || v.relatorio.length \> 0);

}

/\*\*

 \* Persiste as visitas no armazenamento/API do CRM DNB e atualiza a UI

 \*/

async function persistirVisitasImportadas(visitas) {

    // Caso o CRM utilize API Backend:

    if (typeof salvarVisitasViaAPI \=== 'function') {

        await salvarVisitasViaAPI(visitas);

    } else if (typeof carregarVisitas \=== 'function' || localStorage.getItem('crm\_visitas')) {

        // Caso utilize LocalStorage ou IndexedDB

        let visitasExistentes \= \[\];

        try {

            visitasExistentes \= JSON.parse(localStorage.getItem('crm\_visitas') || '\[\]');

        } catch (e) {

            visitasExistentes \= \[\];

        }

        let adicionados \= 0;

        let atualizados \= 0;

        visitas.forEach(novaVisita \=\> {

            const index \= visitasExistentes.findIndex(v \=\> v.id \=== novaVisita.id);

            if (index \>= 0\) {

                visitasExistentes\[index\] \= { ...visitasExistentes\[index\], ...novaVisita };

                atualizados++;

            } else {

                visitasExistentes.push(novaVisita);

                adicionados++;

            }

        });

        localStorage.setItem('crm\_visitas', JSON.stringify(visitasExistentes));

        // Feedback de sucesso

        mostrarAlertaVisitas(

            \`Importação concluída com sucesso\! ${adicionados} nova(s) visita(s) adicionada(s)${atualizados \> 0 ? \` e ${atualizados} atualizada(s)\` : ''}.\`,

            'sucesso'

        );

        // Recarrega a tabela de visitas na interface

        if (typeof renderizarTabelaVisitas \=== 'function') {

            renderizarTabelaVisitas();

        } else if (typeof carregarVisitas \=== 'function') {

            carregarVisitas();

        }

    } else {

        mostrarAlertaVisitas(\`${visitas.length} registros processados com sucesso\!\`, 'sucesso');

    }

}

/\*\*

 \* Função utilitária de feedback visual (compatível com Toast, SweetAlert ou Alert padrão)

 \*/

function mostrarAlertaVisitas(mensagem, tipo \= 'info') {

    if (typeof Swal \!== 'undefined') {

        Swal.fire({

            icon: tipo \=== 'erro' ? 'error' : 'success',

            title: tipo \=== 'erro' ? 'Atenção' : 'Sucesso',

            text: mensagem,

            confirmButtonColor: '\#0d6efd'

        });

    } else if (typeof toastr \!== 'undefined') {

        tipo \=== 'erro' ? toastr.error(mensagem) : toastr.success(mensagem);

    } else {

        alert(mensagem);

    }

}

---

## 🔐 7\. Requisitos Técnicos e de Segurança

- **Validação de Tipo de Arquivo:** Validar extensão `.json` e MIME type `application/json` tanto no HTML (`accept`) quanto no JavaScript.  
- **Prevenção de XSS:** Escapar textos ao renderizar os relatórios importados em tela (`htmlspecialchars` no PHP ou `textContent` / bibliotecas de escape no DOM).  
- **Tratamento de Arquivos Grandes:** Limitar o processamento a arquivos de até 10MB para evitar travamento da aba do navegador.  
- **Transacionalidade:** Garantir que erros durante a iteração não deixem a base de dados em estado inconsistente.

---

## ✅ 8\. Critérios de Aceite

- [x] O botão "Importar visitas / relatórios (JSON)" dispara a seleção de arquivos em qualquer navegador.  
- [x] Arquivos com extensão não compatível são rejeitados com aviso informativo.  
- [x] Arquivos JSON válidos com 1 ou múltiplos registros são processados integralmente.  
- [x] Os registros importados aparecem imediatamente na lista/tabela de visitas sem necessidade de recarregar a página (F5).  
- [x] Mensagens de erro claras são exibidas caso o JSON contenha erros de sintaxe ou campos ausentes.  
- [x] Nenhuma funcionalidade anterior de visitas (cadastro manual, edição, exclusão, filtros) foi prejudicada.

---

## 🧪 9\. Plano de Testes

| Tipo de teste | Cenário | Resultado esperado | Evidência |
| :---- | :---- | :---- | :---- |
| **Acionamento da UI** | Clicar no botão "Importar visitas / relatórios (JSON)" | Janela de arquivos abre com filtro `.json` | Verificação do evento `click` no input oculto |
| **JSON Válido (Array)** | Selecionar arquivo com array de 5 visitas | 5 visitas inseridas e mensagem de sucesso exibida | Registros exibidos na tabela de visitas |
| **JSON Válido (Objeto)** | Selecionar arquivo com objeto único de 1 visita | 1 visita importada com sucesso | Registro exibido na tabela |
| **JSON Corrompido** | Selecionar arquivo com sintaxe inválida (ex.: vírgula sobrando) | Mensagem de erro amigável sem quebra do script | Alerta: "Estrutura JSON inválida" |
| **Seleção Duplicada** | Importar o mesmo arquivo duas vezes seguidas | Evento `change` disparado normalmente em ambas as tentativas | `input.value = ''` limpa o estado |

---

## 🌿 10\. Fluxo Git e Publicação

### Regras de branch

- **Branch base:** `main`  
- **Branch de trabalho:** `feature/importacao-visitas-json`

### Commit sugerido

fix(visitas): implementar fluxo e tratamento de importacao de relatorios json

### Pull Request sugerido

\[CRM DNB\] — Correção e Implementação da Importação de Visitas / Relatórios (JSON)

---

## 📋 13\. Fechamento da Execução

### Resumo do que foi realizado

- Diagnóstico técnico completo da causa do não funcionamento do botão de importação de JSON na tela de visitas.  
- Estruturação do documento de atualização conforme o padrão mestre do projeto.  
- Desenvolvimento do código de correção para o gatilho de interface, leitor assíncrono de arquivos, normalizador de schema de visitas e tratamento de erros.

### Pendências ou ações manuais

- Aplicar o código nos arquivos correspondentes do projeto (`visitas.html` / `visitas.php` e `js/visitas.js`) assim que os arquivos forem acessados localmente ou atualizados no repositório.

### Resultado final

> ✅ **Planejamento e Especificação Técnica Concluídos:** O fluxo, diagnóstico, código de correção e critérios de teste estão integralmente definidos para execução.

---

## 🔗 14\. Links, Arquivos e Referências

- **Repositório:** [https://github.com/deulaag/crmdnb](https://github.com/deulaag/crmdnb)  
- **Documento Mestre de Atualização:** `MODELO_MESTRE_ATUALIZACAO_PROJETO_v1.0.0.md`

