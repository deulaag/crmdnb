(() => {
    const modal = document.querySelector('[data-ai-cmd-modal]');
    const terminal = modal?.querySelector('[data-ai-cmd-terminal]');
    const title = modal?.querySelector('[data-ai-cmd-title]');
    const filesBox = modal?.querySelector('[data-ai-cmd-files]');

    if (!modal || !terminal || !title || !filesBox) return;

    const time = () => new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    }).format(new Date());

    const append = (message, tone = 'normal') => {
        const line = document.createElement('div');
        line.className = 'ai-cmd-line ai-cmd-line-' + tone;

        const stamp = document.createElement('span');
        stamp.className = 'ai-cmd-time';
        stamp.textContent = '[' + time() + ']';

        const text = document.createElement('span');
        text.className = 'ai-cmd-text';
        text.textContent = message;

        line.append(stamp, text);
        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;
    };

    const fileNames = (form) => {
        const names = [];
        form.querySelectorAll('input[type="file"]').forEach((input) => {
            Array.from(input.files || []).forEach((file) => names.push(file.name));
        });
        return names;
    };

    const contextSteps = {
        visitas: [
            [900, 'Validando PDFs e limites do servidor...'],
            [2200, 'Acionando ChatGPT / OpenAI para leitura estruturada da OS...'],
            [4700, 'Lendo OS, data, cliente, vendedor, contato, setor e equipamentos...'],
            [7200, 'Interpretando oportunidades e desfecho registrados no relatório...'],
            [9600, 'Preenchendo obrigatoriamente “Retorno / nova visita sugerida”...'],
            [11800, 'Definindo “Data sugerida para retorno” pelo prazo do relatório ou regra de +60 dias...'],
            [14500, 'Validando campos extraídos e preparando a prévia para conferência...']
        ],
        'orcamento-erp': [
            [900, 'Validando PDFs e limites do servidor...'],
            [2200, 'Acionando ChatGPT / OpenAI para leitura do orçamento ERP...'],
            [5000, 'Extraindo número, data, cliente, vendedor, valor e linha de produto...'],
            [8500, 'Normalizando os dados para o CRM sem criar informações não presentes no documento...'],
            [12500, 'Validando estrutura e preparando o registro da oportunidade...']
        ],
        salesforce: [
            [900, 'Validando o relatório enviado...'],
            [2200, 'Acionando ChatGPT / OpenAI para leitura e normalização do Salesforce...'],
            [5000, 'Identificando oportunidades, clientes, valores, fases e proprietários...'],
            [8200, 'Verificando comissão DNB e motivo da perda quando disponíveis no relatório...'],
            [11800, 'Comparando oportunidades já existentes para evitar duplicidades...'],
            [14500, 'Preparando sincronização do funil de Equipamentos...']
        ]
    };

    const open = (form) => {
        terminal.replaceChildren();
        filesBox.replaceChildren();

        const label = form.dataset.aiCmdLabel || 'Processamento com IA';
        const context = form.dataset.aiCmdContext || 'default';
        title.textContent = label;

        const names = fileNames(form);
        if (names.length) {
            const strong = document.createElement('strong');
            strong.textContent = names.length === 1 ? 'Arquivo:' : 'Arquivos:';
            filesBox.appendChild(strong);

            const list = document.createElement('span');
            list.textContent = names.join(' · ');
            filesBox.appendChild(list);
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('ai-cmd-open');

        append('Processo iniciado pelo usuário.', 'accent');
        if (names.length) append(names.length + ' arquivo(s) preparado(s) para envio.');
        append('Enviando dados ao backend seguro do CRM...');

        const steps = contextSteps[context] || [
            [1200, 'Acionando o agente de IA...'],
            [5000, 'Aguardando resposta estruturada...'],
            [10000, 'Validando dados retornados...']
        ];

        steps.forEach(([delay, message]) => {
            window.setTimeout(() => {
                if (!modal.hidden) append(message);
            }, delay);
        });

        window.setTimeout(() => {
            if (!modal.hidden) {
                append('Aguardando conclusão do servidor. Arquivos maiores podem levar mais tempo.', 'muted');
            }
        }, 20000);
    };

    document.querySelectorAll('form[data-ai-cmd]').forEach((form) => {
        form.addEventListener('submit', () => {
            const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
            if (fileInputs.length && !fileInputs.some((input) => (input.files || []).length > 0)) {
                return;
            }
            if (form.dataset.aiCmdStarted === '1') return;
            form.dataset.aiCmdStarted = '1';
            open(form);
        });
    });
})();