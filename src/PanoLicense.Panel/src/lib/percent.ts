/**
 * Değerleri, toplamı tam 100 olan tam sayı yüzdelere çevirir (en büyük kalan yöntemi).
 * Toplam 0 ise tüm yüzdeler 0 döner.
 */
export function toPercentages(values: number[]): number[] {
  const total = values.reduce((sum, v) => sum + v, 0);
  if (total <= 0) return values.map(() => 0);

  const raw = values.map((v) => (v / total) * 100);
  const result = raw.map(Math.floor);
  let remaining = 100 - result.reduce((sum, v) => sum + v, 0);

  const byRemainder = raw
    .map((v, i) => ({ i, rem: v - Math.floor(v) }))
    .sort((a, b) => b.rem - a.rem);
  for (const { i } of byRemainder) {
    if (remaining <= 0) break;
    result[i] += 1;
    remaining -= 1;
  }
  return result;
}
