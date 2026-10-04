# Foco Hotel API

API REST para gerenciamento de quartos e criação de reservas, com importação de dados de hotéis a partir de XML.

Desenvolvida em PHP e Laravel para o desafio técnico da Foco Multimídia.

## Tecnologias

- PHP e Laravel 13.
- MySQL 8.0.
- Eloquent ORM.
- Docker Compose para o banco de dados.
- PHPUnit para testes automatizados.
- Laravel Scheduler para agendamento da importação.

O Docker Compose atual executa somente o MySQL. O PHP, o Composer e os comandos Artisan são executados na máquina do desenvolvedor.

## Funcionalidades

- Importação de hotéis, quartos, reservas, hóspedes, diárias e pagamentos.
- Reimportação sem duplicação das entidades principais.
- CRUD de quartos.
- Criação de reservas com verificação de disponibilidade.
- Validação de datas e valores.
- Respostas da API em JSON.
- Proteção contra exclusão de quartos com reservas.
- Agendamento de importação a cada hora.
- Registro de inconsistências da importação em logs.

## Requisitos do ambiente

- PHP compatível com Laravel 13 e com as dependências de `composer.json`.
- Composer.
- Docker com suporte a contêineres Linux e Docker Compose.
- Extensões PHP exigidas pelo framework, incluindo:
  - `pdo_mysql`, para o banco da aplicação.
  - `pdo_sqlite`, para os testes.
  - `SimpleXML`, para a importação.
  - `mbstring`, para tratamento de texto.

O projeto foi desenvolvido com PHP 8.5.9 e Laravel Framework 13.34.0.

## Instalação

Após clonar o repositório, entre na pasta do projeto.

Instale as dependências:

```bash
composer install
```

Crie o arquivo de configuração.

Linux:

```bash
cp .env.example .env
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Gere a chave da aplicação:

```bash
php artisan key:generate
```

Inicie o MySQL:

```bash
docker compose up -d mysql
```

Confira os logs e aguarde o servidor indicar `ready for connections`:

```bash
docker compose logs --tail 20 mysql
```

O `.env.example` utiliza esta configuração:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=foco_hotel
DB_USERNAME=foco
DB_PASSWORD=foco_local
```

A porta 3307 da máquina é encaminhada para a porta 3306 do contêiner.
As credenciais do Compose são destinadas ao desenvolvimento local.

Aplique as migrations:

```bash
php artisan config:clear
php artisan migrate
```

Importe os XMLs:

```bash
php artisan hotel-data:import
```

Inicie o servidor de desenvolvimento:

```bash
php artisan serve --port=8001
```

Endereço base da API:

```text
http://127.0.0.1:8001/api
```

O servidor Artisan é destinado ao desenvolvimento. Uma instalação em produção
deve utilizar um servidor web configurado para a pasta `public`.

## Modelagem do banco

As migrations estão em `database/migrations`.

| Tabela | Dados | Relacionamento |
|---|---|---|
| `hotels` | Nome e identificador externo do hotel | Possui vários quartos |
| `rooms` | Nome, hotel e identificador externo | Pertence a um hotel |
| `reservations` | Quarto, entrada, saída, total e identificador externo | Pertence a um quarto |
| `reservation_guests` | Nome, sobrenome e telefone | Pertence a uma reserva |
| `reservation_dailies` | Data e valor da diária | Pertence a uma reserva |
| `reservation_payments` | Método e valor do pagamento | Pertence a uma reserva |

Os campos `id` são identificadores internos do banco.
Os campos `external_id` guardam os códigos recebidos nos XMLs.

As relações utilizam os IDs internos. Durante a importação, os códigos externos
são resolvidos para os registros correspondentes.

Existe uma restrição única para a combinação de reserva e data da diária.

Um hotel com quartos não pode ser excluído pelo banco.
Um quarto com reservas não pode ser excluído.
Os registros de hóspedes, diárias e pagamentos são removidos caso sua reserva
seja excluída.

## Importação XML

Arquivos originais:

```text
database/xml/hotels.xml
database/xml/rooms.xml
database/xml/reserves.xml
```

Execute:

```bash
php artisan hotel-data:import
```

Resultado esperado dos arquivos fornecidos:

- 3 hotéis.
- 6 quartos.
- 6 reservas.
- 6 registros de hóspedes.
- 18 diárias.
- 1 pagamento.

A importação identifica hotéis, quartos e reservas pelo `external_id`,
atualizando registros existentes ou criando novos.

Os hóspedes, diárias e pagamentos das reservas importadas são substituídos
pelo conjunto atual do XML dentro de uma transação. Seus IDs podem mudar
entre importações, mas seus registros não se acumulam.

O XML é a fonte dos valores dos registros importados. Uma reimportação pode
sobrescrever alterações feitas nesses registros pela API.

Registros criados pela API não recebem `external_id` e não são selecionados
para atualização pela importação.

Hotéis e quartos são importados em uma transação. As reservas e seus registros
filhos são importados em outra. Se a importação de reservas falhar, hotéis e
quartos já importados permanecem no banco.

### Inconsistência conhecida

A reserva externa 6 tem entrada em `2022-10-01` e saída em `2022-10-04`,
mas contém uma diária em `2022-12-03`.

O importador preserva esse dado histórico e emite um aviso no terminal e no
log. O arquivo original não é alterado, pois não há confirmação da data correta.

A API de novas reservas rejeita diárias fora do período da estadia.

O método de pagamento `1` é preservado como código textual. Os arquivos
não fornecem seu significado.

## API

As URLs utilizam IDs internos, não os códigos externos dos XMLs.

| Verbo | Rota | Operação |
|---|---|---|
| GET | `/api/rooms` | Lista quartos com paginação |
| GET | `/api/rooms/{id}` | Consulta um quarto |
| POST | `/api/rooms` | Cria um quarto |
| PUT/PATCH | `/api/rooms/{id}` | Atualiza o nome de um quarto |
| DELETE | `/api/rooms/{id}` | Exclui um quarto sem reservas |
| POST | `/api/reservations` | Cria uma reserva |

Envie os cabeçalhos:

```text
Accept: application/json
Content-Type: application/json
```

### Listar quartos

```bash
curl -H "Accept: application/json" \
  http://127.0.0.1:8001/api/rooms
```

A resposta inclui os hotéis relacionados e os dados de paginação.
Cada página contém até 15 quartos.

```text
GET /api/rooms?page=2
```

### Criar um quarto

Use um `hotel_id` existente no seu banco:

```json
{
  "hotel_id": 1,
  "name": "Quarto Standard"
}
```

O `external_id` é reservado à importação e não pode ser atribuído pela API.

### Atualizar um quarto

```json
{
  "name": "Quarto Standard atualizado"
}
```

O nome é obrigatório. Hotel e código externo não podem ser alterados
por essa operação.

### Criar uma reserva

Use um `room_id` existente no seu banco:

```json
{
  "room_id": 1,
  "check_in": "2026-12-01",
  "check_out": "2026-12-03",
  "total": "200.00",
  "guests": [
    {
      "first_name": "Ana",
      "last_name": "Silva",
      "phone": "11999999999"
    }
  ],
  "dailies": [
    {
      "date": "2026-12-01",
      "value": "100.00"
    },
    {
      "date": "2026-12-02",
      "value": "100.00"
    }
  ],
  "payments": [
    {
      "method": "1",
      "value": "50.00"
    }
  ]
}
```

Os valores monetários devem ser enviados como strings, usando ponto decimal.
Pagamentos são opcionais.

### Regras de reservas

- O quarto deve existir.
- A saída deve ocorrer depois da entrada.
- Deve existir ao menos um hóspede.
- Cada noite deve ter uma diária.
- Datas de diárias não podem se repetir.
- A diária deve ocorrer na entrada ou depois, e antes da saída.
- O total deve corresponder à soma das diárias.
- A soma dos pagamentos não pode ultrapassar o total.
- Valores monetários não podem ser negativos.
- Limites por requisição: 50 hóspedes, 365 diárias e 100 pagamentos.
- Datas históricas são aceitas; não existe restrição de entrada futura.

Uma saída no mesmo dia da entrada de outra reserva não constitui sobreposição.

Cada registro de `rooms` representa uma unidade reservável. Não há estoque
de múltiplas unidades por categoria.

A criação utiliza transação e bloqueio do quarto no MySQL. As verificações
de disponibilidade e a gravação acontecem dentro dessa transação.

### Status HTTP

| Status | Significado |
|---|---|
| 200 | Consulta, atualização ou exclusão concluída |
| 201 | Quarto ou reserva criado |
| 404 | Recurso não encontrado |
| 409 | Quarto ocupado ou exclusão bloqueada por reservas |
| 422 | Dados inválidos |

A exclusão retorna 200 com uma mensagem JSON.

## Agendamento no Linux com CRON

O Laravel agenda `hotel-data:import` no início de cada hora.
A configuração está em `routes/console.php`.

Confira:

```bash
php artisan schedule:list
```

No servidor Linux, execute `crontab -e` como o usuário da aplicação e adicione:

```cron
* * * * * cd /caminho/foco-hotel-api && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Ajuste o caminho do projeto e o executável PHP à instalação.

O CRON chama o agendador a cada minuto. O Laravel executa a importação
somente quando ela estiver prevista, no início da hora.

O horário segue o timezone configurado na aplicação, atualmente UTC.

`withoutOverlapping()` impede sobreposição das execuções iniciadas pelo
agendador. Essa proteção não abrange chamadas manuais ao comando.

O usuário responsável precisa acessar o banco e ter permissão de escrita
em `storage` e `bootstrap/cache`.

Para desenvolvimento, inclusive no Windows:

```bash
php artisan schedule:work
```

Esse processo precisa permanecer ativo. Use Ctrl+C para encerrá-lo.

## Logs

- Saída das importações agendadas: `storage/logs/import.log`.
- Avisos e erros da aplicação: `storage/logs/laravel.log`,
  conforme o canal configurado.

## Testes

Execute:

```bash
php artisan test
```

Os testes usam SQLite em memória, configurado em `phpunit.xml`.
Não utilizam o MySQL de desenvolvimento.

Cobertura funcional:

- CRUD e validação de quartos.
- Proteção de quartos com reservas.
- Criação de reservas com registros relacionados.
- Sobreposição de estadias e datas adjacentes.
- Datas, valores e pagamentos inválidos.
- Importação e reimportação sem duplicações.
- Preservação da inconsistência conhecida com aviso.

Os testes em SQLite não comprovam o comportamento de bloqueios concorrentes
do MySQL.

## Limitações e escopo

- A API não implementa autenticação nem permissões por hotel.
- O Compose atual disponibiliza somente o banco.
- Não há endpoints de alteração ou exclusão de reservas.
- Pagamentos são registros locais, sem integração com gateway.
- Não há descontos, cupons ou taxas.
- A importação não remove entidades principais ausentes do XML.
- Atualizações de nomes de registros importados podem ser sobrescritas
  na próxima importação.

Para produção, use `APP_DEBUG=false` e configure credenciais próprias.

## Organização

```text
app/Console/Commands/ImportHotelData.php
app/Http/Controllers/Api/
app/Http/Requests/
app/Models/
app/Services/ReservationService.php
app/Services/ReservationXmlImporter.php
database/migrations/
database/xml/
routes/api.php
routes/console.php
tests/Feature/
```

Os Requests validam a entrada, os Controllers produzem respostas HTTP,
os Services executam as regras e os Models representam os dados e relações.