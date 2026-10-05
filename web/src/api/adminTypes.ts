export interface AdminBatch {
  id: number;
  ticket_type_id: number;
  name: string;
  price_cents: number;
  total: number;
  sold: number;
  reserved: number;
  available: number;
  is_active: boolean;
  created_at: string;
}

export interface AdminTicketType {
  id: number;
  event_id: number;
  name: string;
  batches: AdminBatch[];
}

export interface AdminEvent {
  id: number;
  name: string;
  image_url: string | null;
  ticket_types: AdminTicketType[];
}

export interface TicketHolderInput {
  name: string;
  email: string;
}