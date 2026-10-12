<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * "I accept the terms of use": a required, unmapped checkbox whose label links to the terms on the marketing site.
 * Used by the registration form and by the page shown to accounts that have not accepted the current version.
 */
class TermsCheckboxType extends AbstractType
{
    public function __construct(
        #[Autowire('%env(STATIC_SITE_URL)%')]
        private readonly string $staticSiteUrl,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'required' => true,
            'translation_domain' => 'forms',
            'label' => 'terms.accept',
            'label_html' => true,
            'label_translation_parameters' => ['url' => $this->staticSiteUrl.'/terms-of-use'],
            'constraints' => [new IsTrue(message: 'terms.required')],
        ]);
    }

    public function getParent(): string
    {
        return CheckboxType::class;
    }
}
