import { PortalCard } from "@/components/portal/PortalCard";
import { portals } from "@/lib/config";

export function PortalQuickAccess() {
  const quickPortals = portals.filter((p) => p.key !== "alumni");

  return (
    <div className="relative z-10 border-t border-white/10 bg-pchs-green-900">
      <div className="flex divide-x divide-white/10 overflow-x-auto lg:justify-between">
        {quickPortals.map((portal) => (
          <div key={portal.key} className="flex-1">
            <PortalCard portal={portal} variant="bar" />
          </div>
        ))}
      </div>
    </div>
  );
}
