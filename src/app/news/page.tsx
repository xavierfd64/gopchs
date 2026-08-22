import type { Metadata } from "next";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Card, CardBody } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { DateBadge } from "@/components/ui/DateBadge";
import { news } from "@/data/news";
import { announcements } from "@/data/announcements";

export const metadata: Metadata = {
  title: "News & Announcements",
  description:
    "Latest news and official announcements from Pura Central High School.",
};

export default function NewsPage() {
  return (
    <div className="py-14 sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="News & Events"
          title="News & Announcements"
          description="Sample content shown below — this section will connect to the official Go PCHS content workflow as it comes online."
        />

        <div className="mt-10 grid gap-10 lg:grid-cols-[2fr_1fr]">
          <div className="grid gap-5 sm:grid-cols-2">
            {news.map((article) => (
              <Card key={article.slug}>
                <CardBody>
                  <Badge tone="green">{article.category}</Badge>
                  <h2 className="mt-3 font-display text-lg font-bold text-pchs-green-900">
                    {article.title}
                  </h2>
                  <p className="mt-2 text-sm leading-relaxed text-black/60">
                    {article.excerpt}
                  </p>
                </CardBody>
              </Card>
            ))}
          </div>

          <Card className="h-fit p-5">
            <h2 className="mb-4 font-display text-lg font-bold text-pchs-green-900">
              Announcements
            </h2>
            <ul className="flex flex-col divide-y divide-black/5">
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
        </div>
      </Container>
    </div>
  );
}
