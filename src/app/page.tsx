import { Hero } from "@/components/home/Hero";
import { AnnouncementsSection } from "@/components/home/AnnouncementsSection";
import { NewsSection } from "@/components/home/NewsSection";
import { OneSchoolBanner } from "@/components/home/OneSchoolBanner";
import { AboutPreview } from "@/components/home/AboutPreview";
import { PortalDirectory } from "@/components/portal/PortalDirectory";
import { VisionMissionSection } from "@/components/home/VisionMissionSection";
import { Container } from "@/components/ui/Container";

export default function Home() {
  return (
    <>
      <Hero />

      <section className="py-10 sm:py-14">
        <Container className="grid gap-6 lg:grid-cols-[0.85fr_1fr_0.85fr]">
          <AnnouncementsSection />
          <OneSchoolBanner />
          <NewsSection />
        </Container>
      </section>

      <AboutPreview />
      <PortalDirectory />
      <VisionMissionSection />
    </>
  );
}
