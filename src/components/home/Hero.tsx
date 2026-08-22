import Image from "next/image";
import { siteConfig } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import { Button } from "@/components/ui/Button";
import { PortalQuickAccess } from "@/components/home/PortalQuickAccess";

export function Hero() {
  return (
    <>
      <section className="relative h-[480px] w-full overflow-hidden sm:h-[600px]">
        <Image
          src="/assets/images/hero/pchs-campus-hero.jpg"
          alt={`${siteConfig.schoolName} campus`}
          fill
          priority
          sizes="100vw"
          className="object-cover"
        />
        <div
          aria-hidden="true"
          className="absolute inset-0 bg-gradient-to-b from-pchs-green-900/95 via-pchs-green-900/75 to-pchs-green-900/40 sm:bg-gradient-to-r sm:from-pchs-green-900 sm:via-pchs-green-900/85 sm:to-pchs-green-900/10"
        />
        <GoldSwoosh
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-6 left-0 h-32 w-48 text-pchs-gold-500/80 sm:h-40 sm:w-64"
        />

        <Container className="relative z-10 flex h-full items-center">
          <div className="max-w-xl text-white">
            <p className="text-xs font-bold uppercase tracking-[0.3em] text-pchs-gold-400 sm:text-sm">
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
            <p className="mt-6 text-base leading-relaxed text-white/85 sm:text-lg">
              Empowering learners today for a better tomorrow. One PCHS, one
              family, <span className="text-pchs-gold-400">one future.</span>
            </p>
            <div className="mt-8">
              <Button href="/about" size="lg">
                Learn More About PCHS
              </Button>
            </div>
          </div>
        </Container>
      </section>

      <PortalQuickAccess />
    </>
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
