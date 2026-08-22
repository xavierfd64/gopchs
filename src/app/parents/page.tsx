import type { Metadata } from "next";
import { ComingSoon } from "@/components/ui/ComingSoon";

export const metadata: Metadata = {
  title: "For Parents",
  description: "Information for parents and guardians of Pura Central High School students.",
};

export default function ParentsPage() {
  return (
    <ComingSoon
      title="For Parents"
      description="Enrollment guidance, communication channels, and the Parents Portal will be introduced here as they become available."
    />
  );
}
