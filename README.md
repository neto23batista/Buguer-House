# Burger House

Sistema de pedidos para hamburgueria com cardápio responsivo, carrinho, checkout, painel administrativo e tela de cozinha. Os pedidos são gravados no MySQL com uma cópia dos itens e dos preços da compra. O fluxo inicial é de retirada no balcão com pagamento presencial.

## Pré-requisitos

- PHP 8.1 ou superior com a extensão `pdo_mysql` habilitada. Use `php -m` para conferir.
- MySQL 8.0.22 ou superior em execução e acesso administrativo para importar o SQL e criar o usuário da aplicação.
- Cliente `mysql` no `PATH` para os comandos abaixo. As etapas de importação e criação de usuário também podem ser feitas pelo MySQL Workbench.

## Rodar localmente

1. Abra o terminal na pasta do projeto e inicie o cliente MySQL com o usuário administrativo. Importe os arquivos abaixo na ordem indicada. Os mesmos comandos atualizam instalações anteriores e preservam os produtos, os pedidos e as configurações existentes. `order-security.sql` também converte os tokens de confirmação antigos em hashes. Os links privados em circulação continuam válidos até a data de expiração original.

   ```sh
   mysql --default-character-set=utf8mb4 -u root -p
   ```

   ```sql
   SOURCE database/schema.sql;
   SOURCE database/store.sql;
   SOURCE database/kitchen.sql;
   SOURCE database/tracking.sql;
   SOURCE database/admin-security.sql;
   SOURCE database/order-security.sql;
   ```

2. No console do MySQL, crie o usuário da aplicação ou reaproveite o `bh_web` existente. Use uma senha própria e aplique as permissões abaixo. Quem estiver atualizando uma instalação pode executar apenas os `GRANT`.

   ```sql
   CREATE USER 'bh_web'@'localhost' IDENTIFIED BY 'troque-esta-senha';
   GRANT SELECT ON burger_house.products TO 'bh_web'@'localhost';
   GRANT INSERT, UPDATE (name, description, category, price_cents, image, image_alt, sort_order, is_active) ON burger_house.products TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT ON burger_house.orders TO 'bh_web'@'localhost';
   GRANT UPDATE (status, updated_at) ON burger_house.orders TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT ON burger_house.order_items TO 'bh_web'@'localhost';
   GRANT SELECT ON burger_house.store_settings TO 'bh_web'@'localhost';
   GRANT UPDATE (pickup_address, prep_minutes, paused, schedule, exceptions) ON burger_house.store_settings TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT ON burger_house.order_status_events TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT ON burger_house.order_tracking TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE ON burger_house.admin_login_attempts TO 'bh_web'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE ON burger_house.order_rate_limits TO 'bh_web'@'localhost';
   ```

3. Configure a conexão e inicie o servidor de desenvolvimento a partir da raiz do projeto. As variáveis `DB_HOST`, `DB_PORT` e `DB_NAME` assumem `127.0.0.1`, `3306` e `burger_house` se omitidas.

   **Windows PowerShell**

   ```powershell
   $env:DB_USER = 'bh_web'
   $env:DB_PASSWORD = 'troque-esta-senha'
   $env:ADMIN_USER = 'admin'
   $secret = Read-Host 'Crie uma senha forte para o painel' -AsSecureString
   $env:ADMIN_PASSWORD_HASH = [System.Net.NetworkCredential]::new('', $secret).Password | php -r 'echo password_hash(rtrim(fgets(STDIN)), PASSWORD_DEFAULT);'
   php -S 127.0.0.1:8000 -t public
   ```

   **macOS e Linux (Bash)**

   ```sh
   export DB_USER='bh_web'
   export DB_PASSWORD='troque-esta-senha'
   export ADMIN_USER='admin'
   read -rsp 'Crie uma senha forte para o painel: ' admin_password
   printf '\n'
   export ADMIN_PASSWORD_HASH="$(printf '%s\n' "$admin_password" | php -r 'echo password_hash(rtrim(fgets(STDIN)), PASSWORD_DEFAULT);')"
   unset admin_password
   php -S 127.0.0.1:8000 -t public
   ```

4. Abra o [cardápio](http://127.0.0.1:8000). Adicione dois House Smash e uma Fritas da Casa. Confira o total de **R$ 72,70**, finalize o pedido e anote o código apresentado na confirmação.

5. Abra o [painel da equipe](http://127.0.0.1:8000/admin/) e entre com o usuário e a senha configurados no passo 3. Em **Cozinha**, inicie o preparo e depois marque o pedido como pronto para retirada. A confirmação do cliente atualiza a cada oito segundos enquanto está visível. O link privado de um pedido novo funciona em outro navegador por três dias e mostra somente o andamento e os itens do pedido.

6. No painel, abra **Cardápio** para cadastrar e editar produtos, ajustar preços e definir quais itens aparecem na loja. Para tirar um produto do cardápio, desmarque **Disponível no cardápio**. Os pedidos anteriores mantêm seus itens e preços originais.

Se aparecer a mensagem de cardápio indisponível, confira a conexão e as credenciais no terminal em que o PHP foi iniciado. Se o banco estiver em outra porta, defina `DB_PORT` antes de iniciar o PHP.

## Configuração da loja

- No painel, abra **Loja** para editar endereço, previsão de preparo, horários e feriados. O cadastro inicial aceita pedidos 24h e estima preparo em 25 minutos. Use **Pausar novos pedidos** para suspender temporariamente o checkout. A variável `STORE_PICKUP_ADDRESS` é usada como alternativa quando o endereço do painel está vazio.
- Para cadastrar um operador, defina `OPERATOR_USER` com um login distinto e gere `OPERATOR_PASSWORD_HASH` com o mesmo comando usado para o dono. O operador acessa **Pedidos** e **Cozinha**. O dono também acessa **Cardápio**, **Loja** e **Relatórios**.
- O login limita as tentativas por origem e por conta. A sessão administrativa expira após até 8 horas de inatividade ou 12 horas desde a entrada. O painel renova o prazo de inatividade quando a equipe navega ou executa ações.
- O checkout aceita até 40 unidades por pedido. Em uma janela de 15 minutos, aceita até 3 pedidos por telefone e 20 por origem. Se o endereço de retirada mudar durante o checkout, o cliente revisa o novo endereço antes de confirmar.
- Em **Relatórios**, selecione a data de criação dos pedidos para ver quantidades, valor dos concluídos, ticket médio, itens mais vendidos e motivos registrados de cancelamento.
- Atualize os produtos e preços de demonstração no painel antes de receber pedidos de clientes. Para disponibilizar uma foto nova no seletor do painel, coloque o arquivo em `public/assets/img/`.
- Em produção, use um servidor PHP com HTTPS e raiz pública em `public/`. Defina `SESSION_COOKIE_SECURE=1` para marcar os cookies como `Secure`, inclusive quando o proxy encerra o TLS. Se usar proxy, configure o servidor web para preencher `REMOTE_ADDR` com o endereço do cliente a partir apenas de proxies confiáveis. Reserve `php -S` para desenvolvimento local e armazene as credenciais em variáveis de ambiente do servidor.
- Programe backups do MySQL e teste a restauração em um banco separado. Restrinja o acesso aos logs do servidor e configure-os para omitir a query string de `checkout.php`, que pode conter links privados de acompanhamento.

## Estrutura do projeto

- `public/` é a raiz pública com o cardápio, o checkout, o painel administrativo e os recursos visuais.
- `app/` contém a conexão PDO, a lógica do catálogo e do carrinho, e a gravação e atualização dos pedidos.
- `database/schema.sql` contém o catálogo e os pedidos. Os arquivos `store.sql`, `kitchen.sql` e `tracking.sql` acrescentam configurações, histórico de etapas e acompanhamento. `admin-security.sql` e `order-security.sql` instalam os controles de volume do login e do checkout. Os preços são gravados em centavos e `image` guarda o nome do arquivo em `public/assets/img/`.

As imagens de demonstração dos produtos foram geradas para o projeto com ImageGen. A foto da página inicial é de [Peter Dawn no Unsplash](https://unsplash.com/photos/sxZ_Ca6MkWM), sob a [licença Unsplash](https://unsplash.com/license). As licenças OFL de Barlow Condensed, DM Sans e Young Serif estão em `public/assets/fonts/`.
