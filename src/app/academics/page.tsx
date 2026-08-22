import type { Metadata } from "next";
import { ComingSoon } from "@/components/ui/ComingSoon";

export const metadata: Metadata = {
  title: "Academics",
  description: "Academic programs and learning resources at Pura Central High School.",
};

export default function AcademicsPage() {
  return (
    <ComingSoon
      title="Academics"
      description="Grade levels, subject offerings, and academic programs will be published here in an upcoming development session."
    />
  );
}
