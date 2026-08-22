import { Container } from "@/components/ui/Container";
import { PortalCard } from "@/components/portal/PortalCard";
import { portals } from "@/lib/config";

export function PortalQuickAccess() {
  const quickPortals = portals.filter((p) => p.key !== "alumni");

  return (
    <div className="relative z-10 pb-8 sm:pb-10 lg:-mt-8 lg:pb-0">
      <Container>
        <div className="rounded-2xl border border-white/10 bg-pchs-green-900/95 px-2 py-3 shadow-xl backdrop-blur">
          <div className="flex snap-x gap-1 overflow-x-auto sm:justify-between sm:overflow-visible">
            {quickPortals.map((portal) => (
              <div key={portal.key} className="snap-start">
                <PortalCard portal={portal} variant="bar" />
              </div>
            ))}
          </div>
        </div>
      </Container>
    </div>
  );
}
