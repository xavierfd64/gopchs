import Link from "next/link";
import { announcements } from "@/data/announcements";
import { Card } from "@/components/ui/Card";
import { DateBadge } from "@/components/ui/DateBadge";

export function AnnouncementsSection() {
  return (
    <Card className="flex h-full flex-col p-5">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="font-display text-lg font-bold text-pchs-green-900">
          Announcements
        </h2>
        <Link
          href="/news"
          className="text-xs font-semibold uppercase tracking-wide text-pchs-green-700 hover:text-pchs-gold-600"
        >
          View All
        </Link>
      </div>

      <ul className="flex flex-1 flex-col divide-y divide-black/5">
        {announcements.map((item) => (
          <li key={item.slug} className="flex gap-3 py-3 first:pt-0 last:pb-0">
            <DateBadge date={item.date} />
            <div>
              <p className="text-sm font-semibold text-pchs-ink">
                {item.title}
              </p>
              <p className="mt-1 text-sm leading-snug text-black/60">
                {item.summary}
              </p>
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
