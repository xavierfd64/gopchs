import { Container } from "@/components/ui/Container";
import { PortalCard } from "@/components/portal/PortalCard";
import { portals } from "@/lib/config";

export function PortalQuickAccess() {
  const quickPortals = portals.filter((p) => p.key !== "alumni");

  return (
    <div className="relative z-20 -mt-[52px] px-4 sm:-mt-[46px] sm:px-0">
      <Container>
        <div className="rounded-hero border border-white/10 bg-pchs-green-900/95 px-2 py-3 shadow-elevated backdrop-blur">
          <div className="flex gap-1 overflow-x-auto sm:justify-between">
            {quickPortals.map((portal) => (
              <div key={portal.key} className="shrink-0">
                <PortalCard portal={portal} variant="bar" />
              </div>
            ))}
          </div>
        </div>
      </Container>
    </div>
  );
}
