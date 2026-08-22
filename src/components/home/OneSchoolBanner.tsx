import { siteConfig } from "@/lib/config";
import { Button } from "@/components/ui/Button";
import {
  GraduationCapIcon,
  UsersIcon,
  ChalkboardIcon,
  UserIcon,
} from "@/components/ui/icons";

function yearsOfService() {
  const founded = new Date(siteConfig.founded);
  const now = new Date();
  let years = now.getFullYear() - founded.getFullYear();
  const beforeAnniversary =
    now.getMonth() < founded.getMonth() ||
    (now.getMonth() === founded.getMonth() && now.getDate() < founded.getDate());
  if (beforeAnniversary) years -= 1;
  return years;
}

const stats = [
  {
    icon: GraduationCapIcon,
    value: `${yearsOfService()}+`,
    label: "Years of Service",
  },
  {
    icon: UsersIcon,
    value: "Active",
    label: "Student Government",
  },
  {
    icon: ChalkboardIcon,
    value: "Dedicated",
    label: "Faculty & Staff",
  },
  {
    icon: UserIcon,
    value: "Strong",
    label: "PTA & Community Ties",
  },
];

export function OneSchoolBanner() {
  return (
    <div className="flex h-full flex-col overflow-hidden rounded-card bg-pchs-green-900 text-white shadow-sm">
      <div className="relative flex flex-1 flex-col justify-center gap-4 px-6 py-8 sm:px-8">
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-pchs-gold-500/10 blur-2xl"
        />
        <p className="font-display text-2xl font-black leading-tight sm:text-3xl">
          One School.
          <br />
          One Family.
          <br />
          <span className="text-pchs-gold-400">One Future.</span>
        </p>
        <div>
          <Button href="/about" size="sm">
            Discover Our Story
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-px bg-white/10">
        {stats.map(({ icon: Icon, value, label }) => (
          <div
            key={label}
            className="flex flex-col items-center gap-1.5 bg-pchs-green-900 px-3 py-5 text-center"
          >
            <Icon className="h-5 w-5 text-pchs-gold-400" />
            <span className="text-sm font-bold">{value}</span>
            <span className="text-[11px] leading-tight text-white/60">
              {label}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
