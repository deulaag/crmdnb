# DNB CRM — Design System

Este documento é a referência visual do CRM. Ele preserva a estrutura funcional existente e padroniza apenas UI/UX.

## Direção visual

- Produto: CRM comercial interno / dashboard B2B.
- Estilo: Minimalism & Swiss Style, com baixa ornamentação, hierarquia clara, alto contraste e foco em produtividade.
- Movimento: apenas microinterações funcionais; respeitar `prefers-reduced-motion`.
- Densidade: dashboard moderadamente denso, sem sacrificar legibilidade.
- Feedback: toda notificação transacional e toda confirmação destrutiva deve usar modal acessível; mensagens de ajuda de campo permanecem inline.

## Marca DNB

### Paleta

- Azul petróleo: `#003060`
- Azul claro: `#A4C8FF`
- Azul petróleo / cinza de apoio: `#5F6E7C`
- Fundo do app: `#F4F7FA`
- Superfície: `#FFFFFF`
- Texto principal: `#142033`
- Texto secundário: `#5F6E7C`
- Borda: `#D9E2EA`
- Sucesso: `#177245`
- Atenção: `#A15C00`
- Erro: `#B42318`

### Tipografia

- Títulos, marca e chamadas: Michroma.
- Texto, formulários, tabelas e navegação: Lato.
- Corpo: mínimo visual de 15–16px.
- Texto auxiliar: mínimo 12px.
- Line-height padrão: 1.5.

### Logo

Usar somente `assets/dnb-logo.png`, sem recriar a marca, alterar proporção ou adicionar efeitos.

## Layout

- Conteúdo centralizado com largura máxima de 1320px.
- Cabeçalho institucional azul petróleo.
- Navegação horizontal persistente e rolável em telas menores.
- Cards com borda sutil, fundo branco e sombra mínima.
- Formulários em grid responsivo; controles com altura mínima de 44px.
- Tabelas com cabeçalho legível, hover sutil e rolagem horizontal quando necessário.

## Estados e interação

- `:focus-visible` sempre visível.
- Botões primários em azul petróleo.
- Botões secundários em fundo claro com borda.
- Ações destrutivas sempre exigem confirmação modal.
- Loading precisa desabilitar a ação e alterar o rótulo do botão.
- Não usar emoji como ícone de interface.
- Não depender apenas de cor para significado.
- Não usar gradientes decorativos ou animações contínuas.

## Modais

### Notificação

Usar para:
- sucesso;
- erro;
- atenção;
- informação transacional.

Regras:
- título + mensagem + ação "Entendi";
- foco inicial no botão principal;
- `role="dialog"`, `aria-modal="true"`;
- fechar com Escape quando não for uma confirmação destrutiva.

### Confirmação

Usar antes de:
- excluir;
- descartar;
- desativar;
- executar ação irreversível.

Regras:
- texto explícito da consequência;
- botão secundário "Cancelar";
- botão principal com rótulo da ação;
- foco retorna ao elemento originador ao cancelar.

## Responsividade e acessibilidade

- Testar 375px, 768px, 1024px e 1440px.
- Sem scroll horizontal da página; tabelas podem rolar dentro do próprio container.
- Alvos de interação com pelo menos 44px quando possível.
- Contraste mínimo 4.5:1 para texto normal.
- `prefers-reduced-motion` deve reduzir transições.
- Navegação e modais operáveis por teclado.
