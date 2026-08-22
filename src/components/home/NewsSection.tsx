import Link from "next/link";
import Image from "next/image";
import { news } from "@/data/news";
import { Card } from "@/components/ui/Card";

const MONTHS = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
];

function formatDate(date: string) {
  const d = new Date(`${date}T00:00:00`);
  return `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

export function NewsSection() {
  return (
    <Card className="flex h-full flex-col p-5">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="font-display text-lg font-bold text-pchs-green-900">
          Latest News
        </h2>
        <Link
          href="/news"
          className="text-xs font-semibold uppercase tracking-wide text-pchs-green-700 hover:text-pchs-gold-600"
        >
          View All
        </Link>
      </div>

      <ul className="flex flex-1 flex-col divide-y divide-black/5">
        {news.slice(0, 3).map((article) => (
          <li key={article.slug} className="flex gap-3 py-3 first:pt-0 last:pb-0">
            <div className="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-pchs-green-800/10">
              {article.coverImage ? (
                <Image
                  src={article.coverImage}
                  alt={article.title}
                  fill
                  sizes="56px"
                  className="object-cover"
                />
              ) : (
                <span className="flex h-full w-full items-center justify-center text-[10px] font-semibold uppercase text-pchs-green-700">
                  {article.category.split(" ")[0]}
                </span>
              )}
            </div>
            <div>
              <p className="text-sm font-semibold leading-snug text-pchs-ink">
                {article.title}
              </p>
              <p className="mt-1 text-xs text-black/50">
                {formatDate(article.date)}
              </p>
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
