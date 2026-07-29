# API de Gestão de Propostas

API REST para cadastro de clientes e gestão do ciclo de vida de propostas comerciais, com controle de concorrência, idempotência e trilha de auditoria.

Uma proposta nasce em rascunho, percorre um fluxo de status controlado e termina num estado final imutável. Toda alteração é versionada e auditada.

---

## Stack

| | |
|---|---|
| PHP | 8.2+ (desenvolvido em 8.3) |
| Framework | CodeIgniter 4.7 |
| Banco | MySQL 8 (InnoDB, colunas `JSON` e `ENUM`) |
| Testes | PHPUnit 10.5 |

---

## Instalação

**Requisitos:** PHP 8.2+ com as extensões `mysqli`, `intl`, `mbstring` e `json`; MySQL 8; Composer.

```bash
git clone <url-do-repositorio> && cd teste-tecnico-ts
```

```bash
composer install
```

```bash
cp .env.example .env
```

Ajuste usuário e senha em `.env` se a sua instalação não usar `root` sem senha.

Crie os dois schemas — o de testes precisa ser separado, porque a suíte derruba e recria as tabelas:

```bash
mysql -u root -p -e "CREATE DATABASE teste_tecnico CHARACTER SET utf8mb4; CREATE DATABASE teste_tecnico_test CHARACTER SET utf8mb4;"
```

```bash
php spark migrate
```

```bash
php spark db:seed DatabaseSeeder
```

O seed popula 10 clientes (CPF e CNPJ, incluindo o formato alfanumérico) e 60 propostas distribuídas entre status, origens, faixas de valor e datas, cada uma com trilha de auditoria coerente.

---

## Execução

```bash
php spark serve --port 8080
```

```bash
curl http://localhost:8080/api/v1/health
```

**Documentação interativa:** `http://localhost:8080/docs` — Swagger UI sobre a spec OpenAPI 3.1 em `public/openapi.json`, com *try it out* habilitado. A página carrega o Swagger via CDN, então precisa de internet; a spec em si é servida localmente.

---

## Docker

Alternativa à instalação local. Sobe MySQL 8, PHP-FPM 8.3 e nginx, aplica as migrations e popula os dados:

```bash
docker compose up --build
```

A API fica em `http://localhost:8080` e a documentação em `http://localhost:8080/docs`. O MySQL é publicado em `3307` no host, para não conflitar com uma instalação local na 3306.

Rodar a suíte dentro do container:

```bash
docker compose exec app php vendor/bin/phpunit
```

Duas notas de comportamento:

- A configuração do banco chega por **variável de ambiente**, não por arquivo. O `DotEnv` do CodeIgniter só define variável que ainda não existe, então o `.env` local do host é ignorado dentro do container e permanece intacto.
- O entrypoint roda o seed a cada inicialização, e o `DatabaseSeeder` limpa antes de popular — reiniciar devolve o ambiente ao mesmo conjunto de dados. Para desligar, `SEED_ON_START=false`.

---

## Testes

```bash
composer test
```

**318 testes, 807 asserções.** A suíte migra o banco de testes sozinha — basta o schema `teste_tecnico_test` existir vazio.

| Diretório | O que cobre |
|---|---|
| `tests/unit` | Domínio puro: fluxo de status, CPF/CNPJ, validação de entrada, casts |
| `tests/database` | Repositórios e serviços contra MySQL real |
| `tests/feature` | Os endpoints, da requisição à resposta |

O banco de testes usa MySQL real, e não SQLite em memória, porque o schema depende de `ENUM`, `JSON`, `DECIMAL` e chaves estrangeiras — e duas das regras testadas, optimistic lock e idempotência por constraint `UNIQUE`, dependem do comportamento do MySQL.

---

## Arquitetura

```
Rota → Filter → Controller → DTO → Service → Repository → Model/Entity
                     ↑                                          │
                     └──────── Resource ← ApiResponder ─────────┘
```

| Camada | Responsabilidade |
|---|---|
| **Controller** | Só HTTP: lê headers e corpo, monta DTO, escolhe status code. Sem `try/catch`, sem SQL, sem regra |
| **DTO** | Valida e normaliza a entrada, acumulando todos os erros |
| **Service** | Regra de negócio, transação, auditoria, idempotência |
| **Repository** | Consultas, optimistic lock, joins. Único lugar que vê `DatabaseException` |
| **Entity/Model** | Mapeamento e casts |
| **Resource** | Entity → array do contrato. Decide o que **não** sai |

**Tratamento de erro centralizado.** `BaseApiController::_remap()` envolve toda action num `try/catch` único que traduz `ApiException` em resposta padronizada. Nenhum controller trata erro. O que escapa — rota inexistente, falha inesperada — é coberto por `NotFoundController` e `ApiExceptionHandler`.

---

## Contrato

**Sucesso**

```json
{ "data": { "id": 1, "produto": "Plano Ouro", "valor_mensal": "1250.00" } }
```

**Listagem** — paginação obrigatória em toda coleção:

```json
{ "data": [ ], "meta": { "page": 1, "per_page": 20, "total": 137, "total_pages": 7 } }
```

**Erro** — formato único em toda a API:

```json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "O registro foi alterado por outro processo.",
    "details": { "versao_enviada": 3, "versao_atual": 5 },
    "trace_id": "01e2b666a0112a04"
  }
}
```

O `trace_id` também vai no header `X-Trace-Id` e no log. Se o cliente enviar o header, ele é propagado.

### Códigos de erro

| Código | HTTP | Quando |
|---|---|---|
| `VALIDATION_ERROR` | 422 | Payload ou filtro inválido |
| `MALFORMED_JSON` | 400 | Corpo não é JSON válido |
| `MISSING_IDEMPOTENCY_KEY` | 400 | Header ausente onde é obrigatório |
| `MISSING_VERSION` | 422 | Versão ausente em rota que exige |
| `RESOURCE_NOT_FOUND` | 404 | Id inexistente, excluído ou rota desconhecida |
| `DUPLICATE_RESOURCE` | 409 | `email` ou `documento` já cadastrado |
| `VERSION_CONFLICT` | 409 | Optimistic lock falhou |
| `INVALID_STATUS_TRANSITION` | 409 | Transição fora do fluxo |
| `IMMUTABLE_RESOURCE` | 409 | Registro em estado final |
| `IDEMPOTENCY_KEY_REUSE` | 409 | Mesma chave com payload diferente |
| `RATE_LIMITED` | 429 | Limite de requisições por cliente excedido |
| `INTERNAL_ERROR` | 500 | Falha inesperada (detalhe só no log) |

### Headers

| Header | Direção | Uso |
|---|---|---|
| `Idempotency-Key` | entrada | Obrigatório em `POST /clientes`, `POST /propostas` e `submit`; aceito nas demais transições |
| `If-Match` | entrada | Versão esperada. O campo `versao` no corpo tem precedência |
| `X-Actor` | entrada | Autor da ação na auditoria (ex.: `user:123`). Ausente vira `system` |
| `X-Trace-Id` | ambos | Correlação entre resposta e log |
| `ETag` | saída | Versão atual do recurso, pronta para o `If-Match` seguinte |
| `Idempotency-Replayed` | saída | `true` quando a resposta repete uma operação anterior |
| `X-RateLimit-Limit` / `X-RateLimit-Window` / `Retry-After` | saída | Acompanham o `429` |

### Rate limit

Toda rota sob `/api` é limitada por cliente, identificado pelo IP. O padrão é **60 requisições por minuto**, configurável em `app/Config/RateLimit.php`.

Excedido o limite, a resposta é `429 RATE_LIMITED` no mesmo envelope de erro, com `Retry-After` informando quantos segundos faltam para liberar. A implementação usa o `Throttler` do CodeIgniter — um token bucket sobre o cache configurado — então o limite se reabastece progressivamente, e não em blocos.

O filtro **devolve** a resposta em vez de lançar exceção: filtro roda fora do `try/catch` do controller, e lançar ali tornaria o comportamento não testável.

### Cache de leitura

A leitura individual de proposta (`GET /propostas/{id}`, e todo `findOrFail` interno) é cacheada por 60 segundos, configurável em `app/Config/ReadCache.php`.

O cache vive no `PropostaRepository`, e não numa camada acima, porque é ali que passam tanto a leitura quanto **todas** as escritas — invalidação e cache ficam na mesma classe. Alteração, transição e exclusão descartam a entrada antes de reler.

Ausência não é cacheada: um id ainda inexistente pode ser criado logo depois, e o negativo sobreviveria à criação.

A busca paginada não é cacheada — qualquer escrita afetaria um conjunto indeterminado de combinações de filtro, e ela já custa duas queries fixas.

---

## Endpoints

| Método | Rota | Idempotência | Versão | Sucesso |
|---|---|---|---|---|
| `POST` | `/api/v1/clientes` | obrigatória | — | 201 |
| `GET` | `/api/v1/clientes/{id}` | — | — | 200 |
| `POST` | `/api/v1/propostas` | obrigatória | — | 201 |
| `GET` | `/api/v1/propostas` | — | — | 200 + `meta` |
| `GET` | `/api/v1/propostas/{id}` | — | — | 200 |
| `PATCH` | `/api/v1/propostas/{id}` | — | obrigatória | 200 |
| `DELETE` | `/api/v1/propostas/{id}` | — | obrigatória | 204 |
| `POST` | `/api/v1/propostas/{id}/submit` | obrigatória | obrigatória | 200 |
| `POST` | `/api/v1/propostas/{id}/approve` | opcional | obrigatória | 200 |
| `POST` | `/api/v1/propostas/{id}/reject` | opcional | obrigatória | 200 |
| `POST` | `/api/v1/propostas/{id}/cancel` | opcional | obrigatória | 200 |
| `GET` | `/api/v1/propostas/{id}/auditoria` | — | — | 200 + `meta` |

### Fluxo completo

```bash
curl -X POST http://localhost:8080/api/v1/clientes -H 'Content-Type: application/json' -H 'Idempotency-Key: cli-1' -d '{"nome":"Ana Carolina Souza","email":"ana@exemplo.com","documento":"529.982.247-25"}'
```

```bash
curl -X POST http://localhost:8080/api/v1/propostas -H 'Content-Type: application/json' -H 'Idempotency-Key: prop-1' -H 'X-Actor: user:123' -d '{"cliente_id":1,"produto":"Plano Ouro","valor_mensal":"1250.00","origem":"API"}'
```

```bash
curl -X PATCH http://localhost:8080/api/v1/propostas/1 -H 'Content-Type: application/json' -H 'If-Match: "1"' -H 'X-Actor: user:123' -d '{"produto":"Plano Platina"}'
```

```bash
curl -X POST http://localhost:8080/api/v1/propostas/1/submit -H 'Content-Type: application/json' -H 'Idempotency-Key: sub-1' -d '{"versao":2}'
```

```bash
curl http://localhost:8080/api/v1/propostas/1/auditoria
```

---

## Regras de negócio

### Fluxo de status

```
DRAFT     → SUBMITTED, CANCELED
SUBMITTED → APPROVED, REJECTED, CANCELED
APPROVED, REJECTED, CANCELED → estados finais
```

Estado final recusa transição, `PATCH` e `DELETE` com `IMMUTABLE_RESOURCE`. A guarda de estado é avaliada **antes** da versão: a possibilidade da operação independe de concorrência.

`PATCH` altera `produto`, `valor_mensal` e `origem`, e só em `DRAFT` ou `SUBMITTED`. Status muda apenas pelas rotas de transição.

### Optimistic lock

Toda escrita exige a versão esperada, no corpo (`versao`) ou no header `If-Match`.

```sql
UPDATE propostas SET ..., versao = versao + 1
 WHERE id = ? AND versao = ? AND deleted_at IS NULL
```

Se nenhuma linha for afetada, o registro é relido: sumiu ou foi excluído resulta em 404; existe com outra versão resulta em 409 informando a versão atual.

Um `PATCH` que não altera valor algum devolve a proposta intacta, sem gravar e sem incrementar a versão — a versão sinaliza mudança para quem mantém uma cópia.

### Idempotência

A chave fica numa coluna `UNIQUE` da tabela do próprio recurso: em `clientes` e `propostas` protege a criação; em `proposta_auditorias` protege a transição de status, porque a linha de auditoria **é** o registro de que a transição ocorreu.

Repetir com a mesma chave e o mesmo payload devolve a resposta original com `Idempotency-Replayed: true`. Mesma chave com payload diferente responde `IDEMPOTENCY_KEY_REUSE`. O hash compara valores já normalizados, então reenviar com o documento mascarado ou o e-mail em outra caixa continua sendo a mesma requisição.

Sem tabela intermediária não existe estado de reserva: o `INSERT` do recurso *é* a reserva, e um processo que morre no meio libera a chave por rollback em vez de envenená-la.

### Auditoria

Gravada na **mesma transação** da mudança — as duas comitam juntas ou nenhuma.

| Evento | Payload |
|---|---|
| `CREATED` | snapshot inicial |
| `UPDATED_FIELDS` | `{campo: {de, para}}`, só do que mudou |
| `STATUS_CHANGED` | `{de, para}` |
| `DELETED_LOGICAL` | `{status_no_momento, versao}` |

### Exclusão lógica

`DELETE` preenche `deleted_at`, incrementa a versão e registra `DELETED_LOGICAL`. A linha permanece na tabela; o recurso some das leituras e passa a responder 404.

### Busca

`GET /api/v1/propostas`

| Parâmetro | Formato |
|---|---|
| `cliente_id` | inteiro |
| `status`, `origem` | valor único ou lista separada por vírgula |
| `produto` | busca parcial |
| `valor_min`, `valor_max` | decimal com duas casas |
| `created_from`, `created_to` | `YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS` |
| `q` | texto livre sobre produto, nome e e-mail do cliente |
| `incluir_excluidas` | `true` / `false` (padrão `false`) |
| `sort` | `id`, `created_at`, `updated_at`, `valor_mensal`, `status`. Prefixo `-` inverte. Padrão `-created_at` |
| `page`, `per_page` | padrão 20, máximo 100 |

Data sem hora vira intervalo fechado do dia: `created_to=2026-07-31` inclui tudo até `23:59:59`.

A consulta custa **duas queries independente do volume** — o cliente de cada proposta vem no mesmo `SELECT` por `JOIN`, e o `COUNT` reaproveita o mesmo `WHERE`. Há teste contando as queries emitidas.

Parâmetro desconhecido é recusado com 422: um `?stauts=DRAFT` devolveria a coleção inteira e passaria por filtro funcionando.

### CPF e CNPJ

Aceita CPF (11 dígitos) e CNPJ nos dois formatos — o numérico legado e o **alfanumérico** da IN RFB 2.229/2024, com 12 posições alfanuméricas seguidas de 2 dígitos verificadores numéricos.

Um algoritmo único cobre os dois: a conversão `ASCII − 48` faz o dígito `5` valer 5 e a letra `A` valer 17, mantendo o mesmo módulo 11. Máscara é removida e letras normalizadas para maiúsculas antes de gravar.

---

## Decisões assumidas

O documento não especifica os pontos abaixo.

| Tema | Decisão |
|---|---|
| **Autenticação** | Fora de escopo. O autor da ação vem do header `X-Actor` |
| **`DELETE /propostas/{id}`** | Adicionado além dos endpoints mínimos: a regra de exclusão lógica auditada precisava de porta de entrada |
| **`Idempotency-Key` obrigatória** | Em criação e submit, conforme o documento. Ausente resulta em 400 em vez de degradar em silêncio |
| **Estado final** | Transição, `PATCH` e `DELETE` respondem `IMMUTABLE_RESOURCE`, e não `INVALID_STATUS_TRANSITION`: um código único para "registro encerrado" informa que nenhum destino resolveria |
| **`valor_mensal` como string** | `DECIMAL(12,2)` no banco e string no JSON. Float introduz erro de arredondamento em campo monetário |
| **Cliente inexistente na criação** | 422 no campo `cliente_id`, não 404: o recurso ausente é o referenciado, não o endereçado |
| **Cliente aninhado sem documento** | A listagem não espalha CPF; quem precisa consulta `GET /clientes/{id}` |
| **Timezone** | `America/Sao_Paulo` em toda a aplicação. Timestamps saem em ISO-8601 com offset e são gravados pelo relógio do PHP, nunca por `NOW()` do SQL, para não divergirem quando o MySQL estiver em UTC |
| **Replay devolve 201** | O mesmo status da resposta original. O header sinaliza que nada foi criado desta vez |

---

## Estrutura

```
app/
├── Config/          Rotas, filtros, serviços, exceções
├── Controllers/     HTTP: Api/V1 e o handler de rota desconhecida
├── DTO/             Validação e normalização da entrada
├── Database/        Migrations e seeders
├── Entities/        Casts e comportamento de linha
├── Enums/           Status, origem, evento de auditoria, tipo de documento
├── Exceptions/      ApiException e as concretas
├── Filters/         Contexto de requisição
├── Http/            Envelope, códigos de erro, paginação, trace
├── Models/          Mapeamento CodeIgniter
├── Repositories/    Consultas e optimistic lock
├── Resources/       Entity → contrato
├── Services/        Regra de negócio, transação, auditoria, idempotência
└── ValueObjects/    Documento (CPF/CNPJ)
```
