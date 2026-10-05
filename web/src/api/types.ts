/**
 * Tipos do front derivados do contrato (api/docs/openapi.yaml -> schema.d.ts
 * via `npm run gen:api`). Não declare à mão um tipo que já existe no
 * contrato — sempre que a API mudar, rode `npm run gen:api` de novo e o
 * TypeScript aponta exatamente o que quebrou no front.
 */
import type { components } from './schema';

export type Schemas = components['schemas'];

export type EventSummary = Schemas['EventSummary'];
export type BatchSummary = Schemas['BatchSummary'];
export type Order = Schemas['Order'];
export type OrderStatus = Schemas['OrderStatus'];
export type BuyerInput = Schemas['BuyerInput'];
export type CreateOrderInput = Schemas['CreateOrderInput'];
export type User = Schemas['User'];
export type AuthPayload = Schemas['AuthPayload'];
export type DashboardSnapshot = Schemas['DashboardSnapshot'];
export type DashboardEvent = Schemas['DashboardEvent'];
export type DashboardBatch = Schemas['DashboardBatch'];
export type ApiErrorBody = Schemas['Error'];
export type PaginationLinks = Schemas['PaginationLinks'];
export type PaginationMeta = Schemas['PaginationMeta'];

/** Toda resposta de sucesso da API embrulha o corpo em `data` (ver openapi.yaml). */
export type DataEnvelope<T> = { data: T };

/** Coleções paginadas (ex.: GET /me/orders) trazem `links`/`meta` além de `data`. */
export type PaginatedEnvelope<T> = {
  data: T[];
  links?: PaginationLinks;
  meta?: PaginationMeta;
};
