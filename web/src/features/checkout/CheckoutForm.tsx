import { useEffect } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useCheckout } from './useCheckout';
import { maskCpf, isValidCpf } from '@/lib/cpf';
import { Alert, Button, Field, Input } from '@/components/ui';

const MAX_QUANTITY = 6;

const schema = z
  .object({
    name: z.string().min(2, 'Informe seu nome completo.'),
    email: z.string().email('E-mail inválido.'),
    document: z.string().refine(isValidCpf, 'CPF inválido.'),
    quantity: z.coerce.number().int().min(1).max(MAX_QUANTITY),
    // Titulares por ingresso são OPCIONAIS: sem eles, o titular é o comprador.
    separateHolders: z.boolean(),
    tickets: z.array(z.object({ name: z.string(), email: z.string() })),
  })
  .superRefine((form, ctx) => {
    if (!form.separateHolders) return;
    form.tickets.slice(0, form.quantity).forEach((ticket, i) => {
      if (ticket.name.trim().length < 2) {
        ctx.addIssue({ code: 'custom', path: ['tickets', i, 'name'], message: 'Informe o nome de quem vai usar o ingresso.' });
      }
      if (!z.string().email().safeParse(ticket.email).success) {
        ctx.addIssue({ code: 'custom', path: ['tickets', i, 'email'], message: 'E-mail inválido.' });
      }
    });
  });

type FormData = z.infer<typeof schema>;

export function CheckoutForm({ batchId }: { batchId: number }) {
  const { register, control, handleSubmit, formState, watch, setValue } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: { quantity: 1, separateHolders: false, tickets: [] },
  });
  const { submit, status, attempt, maxAttempts, errorMessage, requestId } = useCheckout();

  const { fields, append, remove } = useFieldArray({ control, name: 'tickets' });
  const quantity = watch('quantity');
  const separateHolders = watch('separateHolders');

  // Com titulares separados, mantém uma linha por ingresso escolhido; sem eles, nenhuma.
  useEffect(() => {
    const target = separateHolders ? Math.min(Math.max(Number(quantity) || 1, 1), MAX_QUANTITY) : 0;
    if (target > fields.length) {
      for (let i = fields.length; i < target; i++) append({ name: '', email: '' });
    } else if (target < fields.length) {
      for (let i = fields.length - 1; i >= target; i--) remove(i);
    }
  }, [quantity, separateHolders, fields.length, append, remove]);

  const onSubmit = (form: FormData) =>
    submit({
      batch_id: batchId,
      quantity: form.quantity,
      buyer: { name: form.name, email: form.email, document: form.document.replace(/\D/g, '') },
      ...(form.separateHolders ? { tickets: form.tickets.slice(0, form.quantity) } : {}),
    });

  const submitting = status === 'submitting';

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6" aria-busy={submitting}>
      <div className="space-y-4">
        <p className="text-sm font-semibold text-slate-700">Dados de quem está comprando</p>

        <Field label="Nome completo" id="name" error={formState.errors.name?.message}>
          <Input id="name" {...register('name')} />
        </Field>

        <Field label="E-mail" id="email" error={formState.errors.email?.message}>
          <Input id="email" type="email" {...register('email')} />
        </Field>

        <Field label="CPF" id="document" error={formState.errors.document?.message}>
          <Input
            id="document"
            inputMode="numeric"
            placeholder="000.000.000-00"
            value={watch('document') ?? ''}
            onChange={(e) => setValue('document', maskCpf(e.target.value), { shouldValidate: formState.isSubmitted })}
          />
        </Field>

        <Field label="Quantidade de ingressos" id="quantity">
          <Input id="quantity" type="number" min={1} max={MAX_QUANTITY} className="w-24" {...register('quantity')} />
        </Field>
      </div>

      <div className="space-y-4 border-t border-slate-100 pt-5">
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" {...register('separateHolders')} />
          Colocar os ingressos no nome de outras pessoas (opcional)
        </label>
        {!separateHolders && (
          <p className="text-xs text-slate-500">Sem isso, todos os ingressos ficam no seu nome.</p>
        )}

        {fields.map((field, index) => (
          <div key={field.id} className="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[auto_1fr_1fr] sm:items-end">
            <span className="text-sm font-medium text-slate-500">Ingresso {index + 1}</span>
            <Field label="Nome do titular" id={`tickets.${index}.name`} error={formState.errors.tickets?.[index]?.name?.message}>
              <Input id={`tickets.${index}.name`} {...register(`tickets.${index}.name` as const)} />
            </Field>
            <Field label="E-mail do titular" id={`tickets.${index}.email`} error={formState.errors.tickets?.[index]?.email?.message}>
              <Input id={`tickets.${index}.email`} type="email" {...register(`tickets.${index}.email` as const)} />
            </Field>
          </div>
        ))}
      </div>

      <Button type="submit" disabled={submitting} aria-busy={submitting} className="w-full">
        {submitting ? `Confirmando sua compra... (tentativa ${attempt} de ${maxAttempts})` : 'Comprar'}
      </Button>

      {errorMessage && (
        <Alert>
          {errorMessage} {requestId && <span className="text-red-400">(ref: {requestId})</span>}
        </Alert>
      )}
    </form>
  );
}
