import { Container } from "@/components/ui/Container";
import { Badge } from "@/components/ui/Badge";

export function ComingSoon({
  title,
  description,
}: {
  title: string;
  description: string;
}) {
  return (
    <Container className="flex flex-col items-start gap-4 py-20">
      <Badge tone="gold">In Development</Badge>
      <h1 className="text-3xl font-bold text-pchs-green-900 sm:text-4xl">
        {title}
      </h1>
      <p className="max-w-2xl text-base leading-relaxed text-black/70">
        {description}
      </p>
    </Container>
  );
}
