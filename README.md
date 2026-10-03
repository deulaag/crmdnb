# DNB CRM Comercial

Reconstrução em PHP + MySQL do modelo local do CRM DNB.

## Instalação

1. Publique os arquivos no diretório do subdomínio.
2. Acesse `install.php`.
3. Informe as credenciais do MySQL e crie o administrador.
4. Entre por `login.php`.

O instalador cria `config/database.local.php` no servidor. Esse arquivo contém as credenciais locais do banco, não é versionado e não usa `.env`.

A instalação não cria clientes, contatos, oportunidades, pedidos, visitas ou usuários demonstrativos. Apenas os funis e linhas de produto estruturais do modelo são cadastrados.
