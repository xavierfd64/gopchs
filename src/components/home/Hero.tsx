import Image from "next/image";
import { siteConfig } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import { Button } from "@/components/ui/Button";
import { PortalQuickAccess } from "@/components/home/PortalQuickAccess";

export function Hero() {
  return (
    <section className="bg-white pt-6 pb-10 sm:pt-8 sm:pb-14">
      <Container>
        <div className="relative">
          <GoldSwoosh
            aria-hidden="true"
            className="pointer-events-none absolute -bottom-8 -left-8 h-40 w-56 text-pchs-gold-400/70 sm:-bottom-12 sm:-left-12 sm:h-56 sm:w-80"
          />

          <div className="relative overflow-hidden rounded-3xl shadow-2xl">
            <div className="grid bg-pchs-green-950 text-white lg:grid-cols-2">
              <div className="relative z-10 flex flex-col justify-center px-6 py-12 sm:px-10 sm:py-16 lg:py-20">
                <div
                  aria-hidden="true"
                  className="pointer-events-none absolute -left-16 top-1/3 h-64 w-64 rounded-full bg-pchs-gold-500/10 blur-3xl"
                />
                <p className="text-xs font-semibold uppercase tracking-[0.3em] text-pchs-gold-400 sm:text-sm">
                  {siteConfig.schoolName} &middot; Est.{" "}
                  {new Date(siteConfig.founded).getFullYear()}
                </p>
                <h1 className="mt-4 font-display font-black leading-[0.95]">
                  <span className="block text-2xl sm:text-3xl lg:text-4xl">
                    Building
                  </span>
                  <span className="block text-5xl uppercase sm:text-6xl lg:text-7xl">
                    Champions
                  </span>
                  <span className="block text-5xl text-pchs-gold-400 sm:text-6xl lg:text-7xl">
                    for Life!
                  </span>
                </h1>
                <p className="mt-6 max-w-md text-base leading-relaxed text-white/75 sm:text-lg">
                  Empowering learners today for a better tomorrow. One PCHS,
                  one family, <span className="text-pchs-gold-400">one future.</span>
                </p>
                <div className="mt-8 flex flex-wrap gap-4">
                  <Button href="/about" size="lg">
                    Learn More About PCHS
                  </Button>
                  <Button href="/portals" variant="outline" size="lg">
                    Visit a Portal
                  </Button>
                </div>
              </div>

              <div className="relative aspect-[4/3] lg:aspect-auto">
                <Image
                  src="/assets/images/hero/pchs-campus-hero.jpg"
                  alt={`${siteConfig.schoolName} campus`}
                  fill
                  priority
                  sizes="(min-width: 1024px) 50vw, 100vw"
                  className="object-cover object-[80%_60%]"
                />
              </div>
            </div>

            <PortalQuickAccess />
          </div>
        </div>
      </Container>
    </section>
  );
}

function GoldSwoosh(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 220 160" fill="none" {...props}>
      <path
        d="M0 160C40 120 30 60 90 40C140 24 170 60 220 40V160H0Z"
        fill="currentColor"
      />
    </svg>
  );
}
