# finance-sim

Simulador mínimo do sistema financeiro do cliente (item 5.2 do desafio).

- `POST /sales` — registra uma venda. Requer header `Idempotency-Key` (a API envia `{order_id}:sale` para vendas e `{order_id}:refund` para estornos — chaves distintas por operação). Deduplica por essa chave, inclusive quando a chamada anterior falhou **depois** de já ter persistido (`FAIL_AFTER_COMMIT_RATE`) — esse é o cenário real que motiva a API nunca confiar apenas no HTTP status para saber se a venda foi registrada.
- `GET /sales` — lista tudo, usado pelo `reconcile:finance` da API.
- `GET /health`.

## Variáveis de ambiente
| Variável | Padrão | Descrição |
|---|---|---|
| `LATENCY_MIN` / `LATENCY_MAX` | 2 / 5 | segundos de latência simulada em toda chamada |
| `FAILURE_RATE` | 0.2 | fração das chamadas que retornam 503 |
| `FAIL_AFTER_COMMIT_RATE` | 0.3 | fração das falhas que acontece **depois** de já ter gravado no SQLite |
| `FINANCE_DB_PATH` | `./finance.sqlite` | caminho do arquivo SQLite |

## Rodar sozinho
```bash
php -S 0.0.0.0:8080 index.php
```
