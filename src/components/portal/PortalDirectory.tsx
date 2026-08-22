import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { PortalCard } from "@/components/portal/PortalCard";
import { portals } from "@/lib/config";

export function PortalDirectory() {
  return (
    <section className="bg-pchs-cream py-16 sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="Go PCHS Ecosystem"
          title="One account, every school service"
          description="Each Go PCHS service lives on its own dedicated portal. Portals launch progressively — until then, they're clearly marked Coming Soon."
          align="center"
        />

        <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {portals.map((portal) => (
            <PortalCard key={portal.key} portal={portal} />
          ))}
        </div>
      </Container>
    </section>
  );
}
