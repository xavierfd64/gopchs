import type { Metadata } from "next";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Button } from "@/components/ui/Button";
import { VisionMissionSection } from "@/components/home/VisionMissionSection";
import { siteConfig } from "@/lib/config";

export const metadata: Metadata = {
  title: "About PCHS",
  description:
    "Learn about Pura Central High School's identity, vision, and mission as part of the Go PCHS digital platform.",
};

export default function AboutPage() {
  return (
    <>
      <div className="bg-pchs-cream py-14 sm:py-20">
        <Container>
          <SectionHeading
            eyebrow="About PCHS"
            title={siteConfig.schoolName}
            description="A public secondary school in Pura, Tarlac, founded by and for its community — and now building its digital home through Go PCHS."
          />

          <div className="mt-10 grid gap-6 sm:grid-cols-2">
            <div className="rounded-card border border-black/5 bg-white p-6">
              <h2 className="font-display text-lg font-bold text-pchs-green-900">
                School Profile
              </h2>
              <dl className="mt-4 space-y-3 text-sm">
                <div className="flex justify-between gap-4">
                  <dt className="text-black/60">Founded</dt>
                  <dd className="font-semibold text-pchs-ink">June 6, 2005</dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-black/60">DepEd School ID</dt>
                  <dd className="font-semibold text-pchs-ink">
                    {siteConfig.depedSchoolId}
                  </dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-black/60">Region</dt>
                  <dd className="font-semibold text-pchs-ink">
                    {siteConfig.region}
                  </dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-black/60">Division</dt>
                  <dd className="font-semibold text-pchs-ink">
                    {siteConfig.division}
                  </dd>
                </div>
              </dl>
            </div>

            <div className="rounded-card border border-black/5 bg-white p-6">
              <h2 className="font-display text-lg font-bold text-pchs-green-900">
                Explore Further
              </h2>
              <p className="mt-3 text-sm leading-relaxed text-black/60">
                Read the complete founding story or meet the founding faculty
                who made Pura Central High School possible.
              </p>
              <div className="mt-4 flex flex-wrap gap-3">
                <Button href="/about/history" variant="secondary" size="sm">
                  School History
                </Button>
                <Button href="/about/faculty-staff" variant="ghost" size="sm">
                  Faculty &amp; Staff
                </Button>
              </div>
            </div>
          </div>
        </Container>
      </div>

      <VisionMissionSection />
    </>
  );
}
