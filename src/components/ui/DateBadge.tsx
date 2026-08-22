const MONTHS = [
  "JAN",
  "FEB",
  "MAR",
  "APR",
  "MAY",
  "JUN",
  "JUL",
  "AUG",
  "SEP",
  "OCT",
  "NOV",
  "DEC",
];

export function DateBadge({ date }: { date: string }) {
  const d = new Date(`${date}T00:00:00`);
  const month = MONTHS[d.getMonth()];
  const day = d.getDate();

  return (
    <div className="flex w-14 shrink-0 flex-col items-center rounded-lg border border-pchs-green-800/15 bg-pchs-cream py-1.5">
      <span className="text-[11px] font-bold tracking-wide text-pchs-green-700">
        {month}
      </span>
      <span className="text-lg font-bold leading-none text-pchs-green-900">
        {day}
      </span>
    </div>
  );
}
