/**
 * Whether paid plans are for sale on the site under test (CIC_PAID_PLANS_OPEN):
 * the pricing page shows the Starter card only when they are.
 */
export async function paidPlansOpen(request) {
  const res = await request.get('/pricing');
  return (await res.text()).includes('data-plan="starter"');
}
