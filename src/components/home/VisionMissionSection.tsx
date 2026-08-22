import { Container } from "@/components/ui/Container";
import {
  ClipboardCheckIcon,
  GraduationCapIcon,
  UsersIcon,
} from "@/components/ui/icons";

const coreValues = ["Maka-Diyos", "Makatao", "Makakalikasan", "Makabansa"];

export function VisionMissionSection() {
  return (
    <section className="bg-pchs-green-900 py-14 text-white sm:py-16">
      <Container className="grid gap-10 lg:grid-cols-3">
        <div>
          <div className="mb-4 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-full border-2 border-pchs-gold-400 text-pchs-gold-400">
              <GraduationCapIcon className="h-5 w-5" />
            </span>
            <h3 className="font-display text-lg font-bold uppercase tracking-wide text-pchs-gold-400">
              Our Vision
            </h3>
          </div>
          <p className="text-sm leading-relaxed text-white/80">
            We dream of Filipinos who passionately love their country and
            whose values and competencies enable them to realize their full
            potential and contribute meaningfully to building the nation.
          </p>
        </div>

        <div>
          <div className="mb-4 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-full border-2 border-pchs-gold-400 text-pchs-gold-400">
              <ClipboardCheckIcon className="h-5 w-5" />
            </span>
            <h3 className="font-display text-lg font-bold uppercase tracking-wide text-pchs-gold-400">
              Our Mission
            </h3>
          </div>
          <p className="text-sm leading-relaxed text-white/80">
            To protect and promote the right of every Filipino to quality,
            equitable, culture-based, and complete basic education. We
            nurture every learner in a safe, inclusive, and motivating
            environment with the support of families and communities.
          </p>
        </div>

        <div>
          <div className="mb-4 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-full border-2 border-pchs-gold-400 text-pchs-gold-400">
              <UsersIcon className="h-5 w-5" />
            </span>
            <h3 className="font-display text-lg font-bold uppercase tracking-wide text-pchs-gold-400">
              Our Core Values
            </h3>
          </div>
          <ul className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm text-white/80">
            {coreValues.map((value) => (
              <li key={value} className="flex items-center gap-2">
                <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-pchs-gold-400" />
                {value}
              </li>
            ))}
          </ul>
        </div>
      </Container>
    </section>
  );
}
