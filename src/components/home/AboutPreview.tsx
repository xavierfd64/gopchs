import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Button } from "@/components/ui/Button";
import { siteConfig } from "@/lib/config";

export function AboutPreview() {
  return (
    <section className="py-16 sm:py-20">
      <Container className="grid gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
        <div>
          <SectionHeading
            eyebrow="About PCHS"
            title={`Since ${new Date(siteConfig.founded).getFullYear()}, a school built by its community`}
            description={`${siteConfig.schoolName} opened its doors on June 6, 2005, with 86 first-year students in a single section — starting classes at the Pura PPSTA Hall while its own school building was being built. Two decades on, that same spirit of community support continues to guide every learner who walks through our gates.`}
          />
          <div className="mt-6">
            <Button href="/about/history" variant="secondary">
              Read Our History
            </Button>
          </div>
        </div>

        <dl className="grid grid-cols-2 gap-4">
          {[
            { label: "Vision", value: "Nation-Building Filipinos" },
            { label: "Mission", value: "Quality, Equitable Education" },
            { label: "Founded", value: "June 6, 2005" },
            { label: "Community", value: "Pura, Tarlac" },
          ].map((item) => (
            <div
              key={item.label}
              className="rounded-card border border-black/5 bg-pchs-cream p-4"
            >
              <dt className="text-xs font-bold uppercase tracking-wide text-pchs-gold-600">
                {item.label}
              </dt>
              <dd className="mt-1 text-sm font-semibold text-pchs-green-900">
                {item.value}
              </dd>
            </div>
          ))}
        </dl>
      </Container>
    </section>
  );
}
