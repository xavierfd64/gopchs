import Image from "next/image";
import { siteConfig } from "@/lib/config";
import { Button } from "@/components/ui/Button";
import {
  ShieldIcon,
  UsersIcon,
  ChalkboardIcon,
  HeartCheckIcon,
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
    icon: ShieldIcon,
    value: `${yearsOfService()}+`,
    label: "Years of Excellence",
  },
  {
    icon: UsersIcon,
    value: "1,200+",
    label: "Active Students",
  },
  {
    icon: ChalkboardIcon,
    value: "50+",
    label: "Dedicated Faculty",
  },
  {
    icon: HeartCheckIcon,
    value: "100%",
    label: "Passion for Education",
  },
];

export function OneSchoolBanner() {
  return (
    <div className="flex h-full flex-col overflow-hidden rounded-card shadow-sm">
      <div className="relative flex flex-1">
        <div className="relative z-10 flex flex-1 flex-col justify-center gap-4 bg-pchs-green-900 px-5 py-8 text-white sm:px-6">
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

        <div className="relative hidden w-2/5 shrink-0 sm:block">
          <Image
            src="/assets/images/students/students-learning-banner.jpg"
            alt="PCHS students together"
            fill
            sizes="200px"
            className="object-cover"
          />
        </div>
      </div>

      <div className="grid grid-cols-2 divide-x divide-y divide-black/5 border-t border-black/5 bg-white">
        {stats.map(({ icon: Icon, value, label }) => (
          <div
            key={label}
            className="flex flex-col items-center gap-1.5 px-3 py-5 text-center"
          >
            <Icon className="h-5 w-5 text-pchs-gold-600" />
            <span className="text-sm font-bold text-pchs-green-900">
              {value}
            </span>
            <span className="text-[11px] leading-tight text-black/50">
              {label}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
