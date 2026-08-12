# DNB CRM - Design System v2.0

Fonte de verdade da interface do CRM Comercial. Este sistema combina o Manual da Marca DNB com as regras de usabilidade do UI/UX Pro Max para dashboards empresariais densos.

## Direção

- Produto: CRM B2B industrial, orientado a metas e operação diária.
- Estilo: minimalismo corporativo, alta legibilidade e densidade controlada.
- Variância: 3/10. Movimento: 3/10. Densidade: 8/10.
- Prioridades: acessibilidade, clareza de status, velocidade de leitura e responsividade.

## Marca

| Token | Valor | Uso |
|---|---:|---|
| `--dnb-petroleum` | `#003060` | Navegação, títulos, ações primárias |
| `--dnb-ocean` | `#A4C8FF` | Estado ativo, foco, realces e superfícies suaves |
| `--dnb-slate` | `#5F6E7C` | Textos secundários e apoio |
| `--dnb-surface` | `#FFFFFF` | Cartões e campos |
| `--dnb-canvas` | `#F4F7FA` | Fundo da aplicação |

- Títulos e chamadas: Michroma, peso regular.
- Textos, dados e controles: Lato, pesos 400, 700 e 900.
- O logotipo deve usar somente as versões previstas no manual: azul sobre fundo claro ou branco sobre fundo escuro.

## Estrutura

- Desktop: sidebar fixa de 264 px, cabeçalho contextual e conteúdo fluido até 1520 px.
- Tablet/celular: sidebar em drawer com sobreposição e fechamento por botão, clique externo ou Escape.
- Navegação: até sete destinos principais, estado ativo evidente e `aria-current`.
- Conteúdo: cartões com cabeçalho, formulários em grades responsivas e tabelas com rolagem horizontal controlada.

## Componentes

- Campos: altura mínima 44 px, rótulo sempre visível, foco com anel azul-oceano.
- Botão primário: fundo azul-petróleo, texto branco e feedback de carregamento.
- Botão secundário: fundo branco, borda clara e realce azul-oceano.
- Ação destrutiva: cor vermelha sem depender apenas do ícone; `aria-label` obrigatório em botões sem texto.
- Cartões: raio 18 px, borda discreta e sombra curta.
- Tabelas: cabeçalho persistente visualmente, números tabulares e estado de hover por linha.
- Tags: cor semântica com texto e contorno, nunca somente cor.

## Acessibilidade e interação

- Contraste mínimo WCAG AA para texto normal.
- Ordem de tabulação igual à ordem visual.
- Link de salto para o conteúdo principal.
- Alvos de toque de pelo menos 44 x 44 px.
- Ícones em SVG; não usar emojis como ícones.
- Transições entre 150 e 220 ms e suporte a `prefers-reduced-motion`.
- Tabelas largas recebem região nomeada e foco visível.
- Formulários apresentam feedback após envio e estados de erro junto ao contexto.

## Breakpoints de validação

- 375 px: celular.
- 768 px: tablet.
- 1024 px: desktop compacto.
- 1440 px: desktop amplo.

## Antipadrões

- Não misturar outras paletas de azul com as cores oficiais.
- Não usar gradientes decorativos na aplicação interna.
- Não remover foco visível.
- Não usar texto menor que 12 px para conteúdo funcional.
- Não depender de hover para revelar ações essenciais.
- Não criar largura fixa que provoque rolagem horizontal da página.
