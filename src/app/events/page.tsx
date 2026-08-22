import type { Metadata } from "next";
import Image from "next/image";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Card, CardBody } from "@/components/ui/Card";
import { DateBadge } from "@/components/ui/DateBadge";
import { events } from "@/data/events";

export const metadata: Metadata = {
  title: "Events & Calendar",
  description: "Upcoming events and school calendar for Pura Central High School.",
};

const gallery = [
  {
    src: "/assets/images/events/school-event.jpg",
    alt: "PCHS school program placeholder photo",
    caption: "School Programs",
  },
  {
    src: "/assets/images/events/basketball-game.jpg",
    alt: "PCHS intramurals basketball placeholder photo",
    caption: "Sports & Intramurals",
  },
];

export default function EventsPage() {
  return (
    <div className="py-14 sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="News & Events"
          title="Events & Calendar"
          description="Sample events shown below. A full manageable school calendar is planned for a future development session."
        />

        <div className="mt-8 grid gap-4 sm:grid-cols-2">
          {gallery.map((item) => (
            <div key={item.src} className="relative">
              <div className="relative aspect-video w-full overflow-hidden rounded-card">
                <Image
                  src={item.src}
                  alt={item.alt}
                  fill
                  sizes="(min-width: 640px) 50vw, 100vw"
                  className="object-cover"
                />
              </div>
              <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-pchs-gold-600">
                {item.caption}
              </p>
            </div>
          ))}
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {events.map((event) => (
            <Card key={event.slug}>
              <CardBody className="flex gap-4">
                <DateBadge date={event.date} />
                <div>
                  <h2 className="font-display text-base font-bold text-pchs-green-900">
                    {event.title}
                  </h2>
                  <p className="mt-1 text-sm leading-relaxed text-black/60">
                    {event.description}
                  </p>
                  {event.location && (
                    <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-pchs-gold-600">
                      {event.location}
                    </p>
                  )}
                </div>
              </CardBody>
            </Card>
          ))}
        </div>
      </Container>
    </div>
  );
}
