"""Gera as planilhas de exemplo da Central de Importação (tests/fixtures/*.xlsx e *.csv).

Uso:  python tests/fixtures/make_fixtures.py
Só usa a biblioteca padrão do Python. Os arquivos gerados ficam versionados; este script documenta o que há dentro.

CNPJs válidos usados nos testes (os mesmos de tests/novocrm_import_test.php):
  A 11.222.333/0001-81  Acme Indústria e Comércio Ltda
  B 45.678.912/0001-55  Beta Metais Ltda
  C 77.889.966/0001-56  Zeta Foods
  D 33.445.522/0001-56  Omega Sistemas
  E 01.234.567/0001-95  Delta Nova (sem zero à esquerda na planilha: 1234567000195)
  F 11.222.333/0002-62  filial de A (mesmo CNPJ raiz)
"""
import os
import zipfile
from xml.sax.saxutils import escape

HERE = os.path.dirname(os.path.abspath(__file__))


def col_letters(i):
    s = ''
    i += 1
    while i:
        i, r = divmod(i - 1, 26)
        s = chr(65 + r) + s
    return s


def sheet_xml(rows, shared):
    """rows: lista de (numero_da_linha, [celulas]); célula = str | int | float | {'inline': str} | None."""
    out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
           '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>']
    for rnum, cells in rows:
        out.append('<row r="%d">' % rnum)
        for ci, v in enumerate(cells):
            if v is None or v == '':
                continue
            ref = '%s%d' % (col_letters(ci), rnum)
            if isinstance(v, dict):
                out.append('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>' % (ref, escape(v['inline'])))
            elif isinstance(v, (int, float)) and not isinstance(v, bool):
                out.append('<c r="%s"><v>%s</v></c>' % (ref, repr(v)))
            else:
                if v not in shared:
                    shared.append(v)
                out.append('<c r="%s" t="s"><v>%d</v></c>' % (ref, shared.index(v)))
        out.append('</row>')
    out.append('</sheetData></worksheet>')
    return ''.join(out)


def write_xlsx(path, sheets, date1904=False):
    """sheets: lista de dicts {name, rows, hidden?}. rows = lista de (numero, celulas)."""
    shared = []
    sheet_files = [sheet_xml(s['rows'], shared) for s in sheets]
    ss = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%d" uniqueCount="%d">%s</sst>'
          % (len(shared), len(shared), ''.join('<si><t xml:space="preserve">%s</t></si>' % escape(s) for s in shared)))
    wb = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
          '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
          'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
          '<workbookPr date1904="1"/>' if date1904 else '<workbookPr/>', '<sheets>']
    rels = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">']
    for i, s in enumerate(sheets, 1):
        state = ' state="hidden"' if s.get('hidden') else ''
        wb.append('<sheet name="%s" sheetId="%d"%s r:id="rId%d"/>' % (escape(s['name']), i, state, i))
        rels.append('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                    'Target="worksheets/sheet%d.xml"/>' % (i, i))
    rels.append('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '
                'Target="sharedStrings.xml"/></Relationships>' % (len(sheets) + 1))
    wb.append('</sheets></workbook>')
    ct = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          '<Default Extension="xml" ContentType="application/xml"/>'
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          + ''.join('<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' % i
                    for i in range(1, len(sheets) + 1))
          + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>')
    root_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                 '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', ct)
        z.writestr('_rels/.rels', root_rels)
        z.writestr('xl/workbook.xml', ''.join(wb))
        z.writestr('xl/_rels/workbook.xml.rels', ''.join(rels))
        z.writestr('xl/sharedStrings.xml', ss)
        for i, xml in enumerate(sheet_files, 1):
            z.writestr('xl/worksheets/sheet%d.xml' % i, xml)


def numbered(header, data, start=1, gaps=()):
    """Numera as linhas a partir de 'start' e pula os números em 'gaps' (linhas vazias que somem no Excel)."""
    rows = [(start, header)]
    n = start
    for r in data:
        n += 1
        while n in gaps:
            n += 1
        rows.append((n, r))
    return rows


# ---------------------------------------------------------------- Contatos
CONT_HEADER = ['Nome', 'Cargo', 'E-mail', 'Telefone', 'Empresa', 'CNPJ', 'Cidade', 'UF', 'ID da empresa']
CONT_ROWS = [
    # 1 Ana: empresa existente (Acme) pelo CNPJ; o CNPJ vem como texto formatado
    ['Ana Souza', 'Compradora', 'ana@acme.com.br', '(11) 99999-0001', 'ACME IND. E COM. LTDA', '11.222.333/0001-81', 'São Paulo', 'SP', None],
    # 2 Bruno: mesma empresa, outro contato (Novo)
    ['Bruno Lima', 'Engenheiro', 'bruno@acme.com.br', '11 98888-0002', 'Acme Indústria e Comércio Ltda', '11222333000181', None, None, None],
    # 3 Carla: o e-mail já existe em outra empresa (Beta) => Revisar
    ['Carla Dias', 'Gerente', 'carla@beta.com.br', '11 97777-0003', 'Gamma Peças', None, None, None, None],
    # 4 Empresa nova sem CNPJ
    ['Diego Ramos', 'Diretor', 'diego@novaempresa.com.br', None, 'Nova Empresa S.A.', None, 'Campinas', 'sp', None],
    # 5 Segundo contato da mesma empresa nova (não pode duplicar a empresa)
    ['Elisa Prado', 'Compras', 'elisa@novaempresa.com.br', None, 'NOVA EMPRESA SA', None, None, None, None],
    # 6 Repetida (igual à linha 1) => Ignorar
    ['Ana Souza', 'Compradora', 'ana@acme.com.br', '(11) 99999-0001', 'ACME IND. E COM. LTDA', '11.222.333/0001-81', 'São Paulo', 'SP', None],
    # 7 Nome em duas empresas (Delta Comercial / Delta Indústria) => Revisar
    ['Fábio Alves', 'Comprador', 'fabio@delta.com.br', None, 'Delta', None, None, None, None],
    # 8 CNPJ inválido, empresa desconhecida => Erro
    ['Gina Melo', 'Analista', 'gina@xyz.com.br', None, 'XYZ Serviços', '123', None, None, None],
    # 9 CNPJ sem zero à esquerda (Excel tirou o 0) => corrigido
    ['Hugo Reis', 'Sócio', 'hugo@deltanova.com.br', None, 'Delta Nova Ltda', 1234567000195, 'Recife', 'PE', None],
    # 10 Filial: mesmo CNPJ raiz de Acme, empresa desconhecida => Revisar com candidata
    ['Iara Costa', 'Gerente', 'iara@acme.com.br', None, 'Acme Filial Sul', '11222333000262', 'Curitiba', 'PR', None],
    # 11 Sem e-mail nem telefone => Erro
    ['Jorge Neves', None, None, None, 'Acme Indústria e Comércio Ltda', None, None, None, None],
]


# ---------------------------------------------------------------- Orçamentos
ORC_HEADER = ['Número', 'Data', 'Cliente', 'CNPJ', 'ID da empresa', 'E-mail do contato', 'Valor', 'Status', 'Linha', 'Vendedor', 'Motivo da perda']
SERIAL_2024_11_26 = 45622
ORC_ROWS = [
    # 1 CNPJ de empresa existente; valor "R$ 1.234,56"; data serial 45622 (= 2024-11-26)
    ['ORC-001', SERIAL_2024_11_26, 'ACME IND. E COM. LTDA', '11.222.333/0001-81', None, None, 'R$ 1.234,56', 'Aprovado', 'Linha A', 'Vendedor Um', None],
    # 2 Sem CNPJ e nome igual a duas empresas => Revisar
    ['ORC-002', '27/11/2024', 'Delta', None, None, None, '1.000,00', 'Aberto', 'Linha A', None, None],
    # 3 Sem CNPJ; nome diferente na grafia mas igual pela chave => name_exact
    ['ORC-003', '28/11/2024', 'ACME IND. E COM. LTDA', None, None, None, '2500,5', 'Em andamento', 'Linha A', 'Vendedor Um', None],
    # 4 Nome parecido mas não igual => Revisar
    ['ORC-004', '28/11/2024', 'Acme Industrial', None, None, None, '3000', 'Aberto', 'Linha A', None, None],
    # 5 ID aponta para Beta e CNPJ para Zeta => Revisar
    ['ORC-005', '29/11/2024', 'Beta Metais', '77.889.966/0001-56', 'EXT-BETA', None, '4000', 'Aberto', 'Linha A', None, None],
    # 6 CNPJ de Omega com nome totalmente diferente => Revisar
    ['ORC-006', '29/11/2024', 'Padaria do Bairro', '33.445.522/0001-56', None, None, '500', 'Aberto', 'Linha A', None, None],
    # 7 external_ref já existe no CRM em outra empresa => Revisar
    ['ORC-007', '30/11/2024', 'Gamma Peças', None, None, None, '700', 'Aberto', 'Linha A', None, None],
    # 8 Status desconhecido => Revisar
    ['ORC-009', '01/12/2024', 'Gamma Peças', None, None, None, '900', 'Em análise X', 'Linha A', None, None],
    # 9 Perdida sem motivo => motivo padrão do lote
    ['ORC-011', '02/12/2024', 'Gamma Peças', None, None, None, '1100', 'Perdido', 'Linha A', None, None],
    # 10 Duplicada idêntica => a segunda é Ignorar
    ['ORC-012', '03/12/2024', 'Gamma Peças', None, None, None, '1200', 'Aberto', 'Linha A', None, None],
    ['ORC-012', '03/12/2024', 'Gamma Peças', None, None, None, '1200', 'Aberto', 'Linha A', None, None],
    # 11 Mesmo número com dados diferentes => as duas viram Erro
    ['ORC-013', '04/12/2024', 'Gamma Peças', None, None, None, '1300', 'Aberto', 'Linha A', None, None],
    ['ORC-013', '04/12/2024', 'Gamma Peças', None, None, None, '1301', 'Aberto', 'Linha A', None, None],
    # 12 Valor inválido e data inválida => Erro
    ['ORC-014', '31/02/2024', 'Gamma Peças', None, None, None, 'abc', 'Aberto', 'Linha A', None, None],
    # 13 Linha de total => Ignorar
    ['Total', None, None, None, None, None, '99999', None, None, None, None],
]

# Orçamentos que se resolvem sozinhos (para a prova de idempotência e de edição no CRM)
ORC_OK_ROWS = [
    ['OK-001', '10/01/2025', 'Acme Indústria e Comércio Ltda', '11.222.333/0001-81', None, None, '1.500,00', 'Aberto', 'Linha A', 'Vendedor Um', None],
    ['OK-002', '11/01/2025', 'Gamma Peças', None, None, None, 'R$ 2.750,40', 'Ganho', 'Linha A', 'Vendedor Um', None],
    ['OK-003', '12/01/2025', 'Beta Metais Ltda', '45.678.912/0001-55', None, None, '980,00', 'Perdido', 'Linha A', None, 'Preço'],
    ['OK-004', '13/01/2025', 'Epsilon Brasil', None, 'EXT-EPS', None, '5000', 'Aberto', 'Linha A', None, None],
]


def main():
    write_xlsx(os.path.join(HERE, 'contatos.xlsx'), [
        # A 1ª aba (sheet1.xml) está OCULTA e tem lixo: o leitor não pode usar sheet1.xml às cegas.
        {'name': 'Oculta', 'hidden': True, 'rows': [(1, ['lixo', 'lixo']), (2, ['x', 'y'])]},
        # Linhas vazias nos números 5 e 9 (somem no Excel): o número real da linha precisa ser preservado.
        {'name': 'Contatos', 'rows': numbered(CONT_HEADER, CONT_ROWS, gaps=(5, 9))},
        {'name': 'Outra aba', 'rows': [(1, ['a', 'b'])]},
    ])
    write_xlsx(os.path.join(HERE, 'orcamentos.xlsx'), [{'name': 'Propostas', 'rows': numbered(ORC_HEADER, ORC_ROWS)}])
    write_xlsx(os.path.join(HERE, 'orcamentos_ok.xlsx'), [{'name': 'Propostas', 'rows': numbered(ORC_HEADER, ORC_OK_ROWS)}])
    # inlineStr e planilha em base 1904
    write_xlsx(os.path.join(HERE, 'orcamentos_inline_1904.xlsx'), [{'name': 'Dados', 'rows': [
        (1, [{'inline': 'Número'}, {'inline': 'Data'}, {'inline': 'Cliente'}, {'inline': 'Valor'}]),
        (2, [{'inline': 'INL-1'}, 44160, {'inline': 'Gamma Peças'}, 10.5]),
    ]}], date1904=True)

    # CSV em Windows-1252 com ponto e vírgula (como o Excel brasileiro salva)
    csv_rows = [['Número', 'Data da emissão', 'Cliente', 'Total (R$)', 'Status', 'Conta no valor']] + [
        ['CSV-1', '05/01/2025 14:30', 'Gamma Peças', '1.234,56', 'Aberto', 'sim'],
        ['CSV-2', '06/01/2025 09:00', 'Gamma Peças', '2.000,00', 'Aberto', 'não (reemitida depois)'],
    ]
    with open(os.path.join(HERE, 'orcamentos.csv'), 'w', encoding='cp1252', newline='') as f:
        for r in csv_rows:
            f.write(';'.join(r) + '\r\n')
    print('Fixtures geradas em', HERE)


if __name__ == '__main__':
    main()
